// Failure scenarios (brief section 24), each in real browsers on the integrated app. Time-dependent rules (search
// window, free cancellation window, payment retries, PIN lock, document expiry) are reached by moving timestamps back
// in the database and running the scheduler's command, as the host's cron does.
import test from 'node:test';
import assert from 'node:assert/strict';
import { newContext, watch, step, sql, one, until, stub, interceptCheckout, makePerson, world, artisan, api, sleep, shot, closeBrowser } from '../lib/harness.mjs';
import { addCard, priceTrip, requestCategory, paxTitle, paxPin, goOnline, waitOffer, accept, driveTo, arrive, startTrip, complete, typePin, ledgerBalanced } from '../lib/flows.mjs';
import { PICKUP, DEST, DRIVER_START } from '../lib/geo.mjs';

const FAR_START = [-33.952, 18.41]; // 1.3 km south of the pickup
const rand = (minor) => `R${(minor / 100).toFixed(2)}`;

test.beforeEach(() => world('offline-all'));
test.after(async () => { world('offline-all'); await stub({ routingDown: false, chargeOutcome: 'success' }); await closeBrowser(); });

async function passenger({ card = true } = {}) {
    const pax = makePerson('user', 'Passenger');
    const ctx = await newContext({ user: pax, geo: PICKUP });
    await interceptCheckout(ctx);
    const page = await ctx.newPage();
    const w = watch(page);
    if (card) await addCard(page);
    return { pax, ctx, page, w };
}

async function onlineDriver(at = DRIVER_START, opts = {}) {
    const driver = makePerson('verified-driver', 'Driver', opts);
    const ctx = await newContext({ user: driver, geo: at });
    const page = await ctx.newPage();
    const w = watch(page);
    await goOnline(page, driver.id);
    return { driver, ctx, page, w };
}

test('routing provider down: honest "unavailable", nothing priced', async () => {
    const { page, pax, ctx } = await passenger({ card: false });
    await stub({ routingDown: true });
    try {
        await page.goto('/ride?new=1');
        const { searchPlace } = await import('../lib/flows.mjs');
        await searchPlace(page, '#pax-pickup', 'Kloof');
        await searchPlace(page, '#pax-dropoff', 'Bree');
        await page.click('[data-pax-see-prices]');
        await page.getByText(/unavailable|could not|not available/i).first().waitFor({ timeout: 20000 });
        const text = (await page.locator('main').innerText()).replace(/\s+/g, ' ');
        assert.match(text, /rout/i, 'the message names routing');
        assert.equal(await page.locator('.pax-option').count(), 0, 'no price is shown');
        assert.equal(one('select count(*) n from fare_quotes where passenger_id = ?', [pax.id]).n, 0, 'no quote stored');
        assert.ok((await stub()).routeRequests > 0, 'the provider was asked');
        assert.equal(await page.locator('[data-pax-request]:enabled').count(), 0, 'nothing can be requested');
    } finally { await stub({ routingDown: false }); }
    await ctx.close();
});

test('no drivers: the ride expires with the honest message, nothing charged', async () => {
    const { page, pax, ctx } = await passenger();
    await priceTrip(page);
    const rideId = await requestCategory(page, 'Economy');
    await paxTitle(page, /looking|search|finding/i, 20000);
    world('shift', { table: 'rides', id: rideId, columns: ['requested_at', 'search_started_at', 'created_at'], seconds: 11 * 60 });
    await until(() => one("select id from rides where id = ? and status = 'expired'", [rideId]), { what: 'expiry on the next poll', timeout: 20000 });
    await page.getByText(/No driver found/i).first().waitFor({ timeout: 15000 });
    assert.match(await page.locator('main').innerText(), /not charged/i);
    assert.equal(one("select count(*) n from ride_payments where ride_id = ?", [rideId]).n, 0);
    const fare = one('select * from ride_fares where ride_id = ?', [rideId]);
    assert.equal(fare.final_total_minor, 0, 'fare record closed at zero');
    assert.equal(one('select active_passenger_id from rides where id = ?', [rideId]).active_passenger_id, null, 'the passenger can book again');
    await ctx.close();
});

test('driver declines, next driver offered at once; an unanswered offer times out to the next driver', async () => {
    const near = await onlineDriver(DRIVER_START);
    const far = await onlineDriver(FAR_START);
    const { page, ctx } = await passenger();

    await priceTrip(page);
    const ride1 = await requestCategory(page, 'Economy');
    await waitOffer(near.page);
    await near.page.click('[data-dr-decline]');
    await near.page.check('input[name=reason][value=destination]');
    await near.page.click('[data-dr-decline-form] button[type=submit]');
    await waitOffer(far.page, 15000);
    const offers1 = sql('select driver_id, status, reason from ride_offers where ride_id = ? order by id', [ride1]);
    assert.equal(offers1[0].driver_id, near.driver.id);
    assert.equal(offers1[0].status, 'declined');
    assert.equal(offers1[0].reason, 'destination');
    assert.equal(offers1[1].driver_id, far.driver.id);
    await accept(far.page);
    // The passenger cancels this one (free: inside the window) to free both drivers.
    await page.click('[data-pax-cancel]');
    await page.locator('[data-pax-cancel-cost]').waitFor();
    assert.match(await page.locator('[data-pax-cancel-cost]').innerText(), /free/i);
    await page.click('[data-pax-cancel-confirm]');
    await until(() => one("select id from rides where id = ? and status = 'cancelled_by_passenger'", [ride1]), { what: 'cancelled' });
    assert.equal(one('select cancellation_fee_minor from rides where id = ?', [ride1]).cancellation_fee_minor ?? 0, 0);

    // Timeout: the near driver lets the offer run out (15 s); the far driver gets it on the next poll.
    await near.page.goto('/drive'); // decline cooldown aside, the nearest driver is still first by ETA
    await priceTrip(page);
    const ride2 = await requestCategory(page, 'Economy');
    const firstOffer = await until(() => one("select driver_id from ride_offers where ride_id = ? order by id limit 1", [ride2]), { what: 'first offer' });
    const first = firstOffer.driver_id === near.driver.id ? near : far;
    const second = first === near ? far : near;
    await waitOffer(first.page);
    await waitOffer(second.page, 40000);
    const offers2 = sql('select driver_id, status, reason from ride_offers where ride_id = ? order by id', [ride2]);
    assert.equal(offers2[0].status, 'expired');
    assert.equal(offers2[0].reason, 'timeout');
    assert.equal(offers2[1].driver_id, second.driver.id);
    assert.equal(offers2[1].status, 'pending');
    await page.click('[data-pax-cancel]');
    await page.click('[data-pax-cancel-confirm]');
    for (const c of [near.ctx, far.ctx, ctx]) await c.close();
});

test('passenger cancels after assignment: free in the window, the policy fee after it (card charged, driver share)', async () => {
    const drv = await onlineDriver();
    const { page, pax, ctx } = await passenger();
    await priceTrip(page);
    const rideId = await requestCategory(page, 'Economy');
    await waitOffer(drv.page);
    await accept(drv.page);
    await paxTitle(page, /on the way/i);
    // The free window (2 min in the example tariff) has passed.
    world('shift', { table: 'rides', id: rideId, columns: ['accepted_at', 'driver_arriving_at'], seconds: 180 });
    await page.reload();
    await page.click('[data-pax-cancel]');
    const cost = await page.locator('[data-pax-cancel-cost]').innerText();
    assert.match(cost, /R25\.00/, `the cost is said first: ${cost}`);
    await page.click('[data-pax-cancel-confirm]');
    const ride = await until(() => one("select * from rides where id = ? and status = 'cancelled_by_passenger'", [rideId]), { what: 'cancelled' });
    assert.equal(ride.cancellation_fee_minor, 2500);
    const pay = await until(() => one("select * from ride_payments where ride_id = ? and purpose = 'cancellation_fee' and status = 'paid'", [rideId]), { what: 'fee charged' });
    assert.equal(pay.amount_cents, 2500);
    const share = await until(() => one("select * from driver_earnings where ride_id = ? and kind = 'cancellation_fee'", [rideId]), { what: 'driver share' });
    assert.equal(share.net_minor, 2000, 'the driver gets 80 %');
    assert.equal(share.driver_id, drv.driver.id);
    assert.ok(ledgerBalanced());
    await drv.page.waitForSelector('[data-dr-view="online"]:not([hidden])', { timeout: 15000 });
    for (const c of [drv.ctx, ctx]) await c.close();
    assert.ok(pax.id);
});

test('payment declined: retries, then payment_due blocks the next request; pay now clears it', async () => {
    const drv = await onlineDriver();
    const { page, pax, ctx } = await passenger();
    await priceTrip(page);
    const rideId = await requestCategory(page, 'Economy');
    await waitOffer(drv.page);
    await accept(drv.page);
    await driveTo(drv.ctx, drv.page, DRIVER_START, PICKUP);
    await arrive(drv.page);
    await startTrip(drv.page, await paxPin(page));
    await driveTo(drv.ctx, drv.page, PICKUP, DEST);
    await stub({ chargeOutcome: 'failed' });
    try {
        await complete(drv.page);
        const failed = await until(() => one("select * from ride_payments where ride_id = ? and purpose = 'ride_fare' and status = 'failed'", [rideId]), { what: 'declined charge' });
        assert.ok(failed.next_retry_at, 'a retry is scheduled');
        // The retries run out (15 min, 2 h, 24 h): move each one due and run the sweep, as cron does every minute.
        for (let i = 0; i < 5; i++) {
            for (const row of sql("select id from ride_payments where ride_id = ? and next_retry_at is not null", [rideId])) world('shift', { table: 'ride_payments', id: row.id, columns: ['next_retry_at', 'created_at', 'updated_at'], seconds: 2 * 86400 });
            artisan('money:sweep');
        }
        const attempts = sql("select status from ride_payments where ride_id = ? and purpose = 'ride_fare'", [rideId]);
        assert.ok(attempts.length >= 2 && attempts.every((a) => a.status === 'failed'), `attempts: ${JSON.stringify(attempts)}`);
        const due = await api(page, 'GET', '/money/payment-due');
        assert.equal(due.data.blocked ?? due.data.payment_due?.blocked, true, `blocked: ${JSON.stringify(due.data).slice(0, 300)}`);

        // The next request is refused with the amount and a way to pay.
        await priceTrip(page);
        const option = page.locator('.pax-option').first();
        await option.locator('.pax-option__pick').check();
        await page.click('[data-pax-request]');
        const block = page.locator('[data-pax-due]');
        await block.waitFor({ timeout: 15000 });
        const finalFare = one('select final_fare_minor from rides where id = ?', [rideId]).final_fare_minor;
        assert.ok((await block.innerText()).includes(rand(finalFare)), 'the unpaid amount is shown');
        assert.equal(one("select count(*) n from rides where passenger_id = ? and id > ?", [pax.id, rideId]).n, 0, 'no new ride was created');

        // Pay now (the bank approves this time) clears it; the request then goes through.
        await stub({ chargeOutcome: 'success' });
        await block.getByRole('button', { name: /^Pay R/ }).click();
        await until(() => one("select id from ride_payments where ride_id = ? and purpose = 'ride_fare' and status = 'paid'", [rideId]), { what: 'paid now' });
        await block.waitFor({ state: 'detached', timeout: 15000 });
        await page.locator('.pax-option').first().locator('.pax-option__pick').check();
        await Promise.all([page.waitForURL(/\/ride\/trips\/\d+/, { timeout: 20000 }), page.click('[data-pax-request]')]);
        const next = Number(page.url().match(/trips\/(\d+)/)[1]);
        assert.ok(next > rideId);
        await page.click('[data-pax-cancel]');
        await page.click('[data-pax-cancel-confirm]');
    } finally { await stub({ chargeOutcome: 'success' }); }
    assert.ok(ledgerBalanced());
    for (const c of [drv.ctx, ctx]) await c.close();
});

test('an unverified driver cannot go online', async () => {
    const driver = makePerson('verified-driver', 'Unverified', { documents: false });
    const ctx = await newContext({ user: driver, geo: DRIVER_START });
    const page = await ctx.newPage();
    await page.goto('/drive');
    const text = (await page.locator('main').innerText()).replace(/\s+/g, ' ');
    assert.match(text, /document/i, 'the console says documents are missing');
    const button = page.locator('[data-dr-go-online]');
    assert.ok((await button.count()) === 0 || (await button.isDisabled()), 'Go online is not available');
    const r = await api(page, 'POST', '/api/ehail/driver/online');
    assert.ok([403, 409, 422].includes(r.status), `refused ${r.status}`);
    assert.match(JSON.stringify(r.data), /document_missing/);
    assert.equal(one("select count(*) n from driver_availability where user_id = ? and state = 'online'", [driver.id]).n, 0);
    await ctx.close();
});

test('an expired document takes an online driver offline', async () => {
    const drv = await onlineDriver();
    const doc = one("select id from verification_documents where user_id = ? and type = 'drivers_licence'", [drv.driver.id]);
    world('update', { table: 'verification_documents', where: { id: doc.id }, set: { expires_on: '2026-01-01' } });
    artisan('ehail:verification-expiry');
    assert.equal(one('select status from verification_documents where id = ?', [doc.id]).status, 'expired');
    assert.equal(one('select state from driver_availability where user_id = ?', [drv.driver.id]).state, 'offline');
    await drv.page.waitForSelector('[data-dr-view="offline"]:not([hidden])', { timeout: 40000 });
    await drv.page.reload();
    assert.match((await drv.page.locator('main').innerText()).replace(/\s+/g, ' '), /expired/i, 'the console says why');
    await drv.ctx.close();
});

test('the same request twice with one Idempotency-Key makes one ride; a double tap makes one ride', async () => {
    const { page, pax, ctx } = await passenger();
    await priceTrip(page);
    const quote = one("select id from fare_quotes where passenger_id = ? and consumed_at is null order by created_at desc limit 1", [pax.id]);
    const body = { quote_id: quote.id, payment_method: 'card', pickup_address: 'Kloof Street', dropoff_address: 'Bree Street' };
    const key = `e2e-key-${Date.now()}`;
    const [a, b] = await Promise.all([api(page, 'POST', '/api/ehail/rides', body, { 'Idempotency-Key': key }), api(page, 'POST', '/api/ehail/rides', body, { 'Idempotency-Key': key })]);
    const statuses = [a.status, b.status].sort();
    assert.ok(statuses.every((s) => [201, 409].includes(s)) || statuses.every((s) => s === 201), `statuses ${statuses}`);
    const replay = await api(page, 'POST', '/api/ehail/rides', body, { 'Idempotency-Key': key });
    assert.equal(replay.status, 201);
    assert.equal(replay.headers['idempotent-replayed'], 'true');
    assert.equal(one('select count(*) n from rides where passenger_id = ?', [pax.id]).n, 1, 'one ride');
    const other = await api(page, 'POST', '/api/ehail/rides', { ...body, pickup_address: 'Somewhere else' }, { 'Idempotency-Key': key });
    assert.ok(other.status >= 400, 'same key with a different body is refused');
    await api(page, 'POST', `/api/ehail/rides/${replay.data.ride.id}/cancel`, { reason: 'changed_plans' });

    // Double tap on Request in the booking sheet.
    await priceTrip(page);
    await page.locator('.pax-option').first().locator('.pax-option__pick').check();
    await page.locator('[data-pax-request]').dblclick();
    await page.waitForURL(/\/ride\/trips\/\d+/, { timeout: 20000 });
    await sleep(1500);
    assert.equal(one("select count(*) n from rides where passenger_id = ? and status in ('requested','searching')", [pax.id]).n, 1, 'one ride from a double tap');
    await page.click('[data-pax-cancel]');
    await page.click('[data-pax-cancel-confirm]');
    await ctx.close();
});

test('two drivers accept at the same moment: exactly one wins', async () => {
    const a = await onlineDriver(DRIVER_START);
    const b = await onlineDriver(FAR_START);
    const { page, ctx } = await passenger();
    await priceTrip(page);
    const rideId = await requestCategory(page, 'Economy');
    const offer = await until(() => one("select * from ride_offers where ride_id = ? and status = 'pending'", [rideId]), { what: 'offer' });
    const holder = offer.driver_id === a.driver.id ? a : b;
    const other = holder === a ? b : a;
    // The ride is also on the open board (after 45 s of searching) for the other driver.
    world('shift', { table: 'rides', id: rideId, columns: ['search_started_at', 'requested_at'], seconds: 60 });
    await other.page.goto('/rides/available');
    const claim = other.page.locator(`form[action$="/rides/${rideId}/accept"]`);
    await claim.waitFor({ timeout: 10000 });
    const token = await claim.locator('input[name=_token]').inputValue();
    const [r1, r2] = await Promise.all([
        api(holder.page, 'POST', `/api/ehail/offers/${offer.id}/accept`),
        other.page.evaluate(async ([url, t]) => { const r = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' }, body: `_token=${encodeURIComponent(t)}`, redirect: 'manual', credentials: 'same-origin' }); return { status: r.status }; }, [`/rides/${rideId}/accept`, token]),
    ]);
    const ride = one('select * from rides where id = ?', [rideId]);
    assert.equal(ride.status, 'driver_assigned');
    assert.ok([a.driver.id, b.driver.id].includes(ride.driver_id));
    const holding = sql("select user_id from driver_availability where current_ride_id = ?", [rideId]);
    assert.equal(holding.length, 1, `one driver holds the ride (offer accept ${r1.status}, claim ${r2.status})`);
    assert.equal(holding[0].user_id, ride.driver_id);
    assert.equal(one("select count(*) n from ride_events where ride_id = ? and to_status = 'driver_assigned'", [rideId])?.n ?? 1, 1);
    await page.click('[data-pax-cancel]');
    await page.click('[data-pax-cancel-confirm]');
    for (const c of [a.ctx, b.ctx, ctx]) await c.close();
});

test('connection loss on the driver: stale indicators, then recovery; PIN brute force locks', async () => {
    const drv = await onlineDriver();
    const { page, ctx } = await passenger();
    await priceTrip(page);
    const rideId = await requestCategory(page, 'Economy');
    await waitOffer(drv.page);
    await accept(drv.page);
    await paxTitle(page, /on the way/i);

    await drv.ctx.setOffline(true);
    await drv.page.getByText(/No connection to Dot\.Ehail/).waitFor({ timeout: 20000 });
    await page.locator('[data-pax-stale]:not([hidden])').waitFor({ timeout: 70000 });
    assert.match(await page.locator('[data-pax-stale]').innerText(), /Last position/i);
    await drv.ctx.setOffline(false);
    await drv.page.getByText(/No connection to Dot\.Ehail/).waitFor({ state: 'detached', timeout: 20000 });
    await page.locator('[data-pax-stale]').waitFor({ state: 'hidden', timeout: 30000 });

    // PIN brute force: five wrong PINs lock it; even the right one is then refused until the lock ends.
    await driveTo(drv.ctx, drv.page, DRIVER_START, PICKUP);
    await arrive(drv.page);
    const pin = await paxPin(page);
    const wrong = pin === '0000' ? '1111' : '0000';
    for (let i = 0; i < 5; i++) {
        await typePin(drv.page, wrong);
        await sleep(700);
    }
    const locked = one('select pin_attempts, pin_locked_until from rides where id = ?', [rideId]);
    assert.ok(locked.pin_locked_until, 'locked');
    assert.match(await drv.page.locator('[data-dr-pin-error]').innerText(), /lock|too many|wait/i);
    const right = await api(drv.page, 'POST', `/api/ehail/driver/rides/${rideId}/start`, { pin });
    assert.ok(right.status >= 400, 'the right PIN is refused while locked');
    assert.equal(one('select status from rides where id = ?', [rideId]).status, 'driver_arrived');
    assert.ok(one("select count(*) n from audit_logs where action like '%pin%'").n >= 5, 'wrong PINs audited');
    // When the lock ends the right PIN works.
    world('update', { table: 'rides', where: { id: rideId }, set: { pin_locked_until: '2000-01-01 00:00:00' } });
    const ok = await api(drv.page, 'POST', `/api/ehail/driver/rides/${rideId}/start`, { pin });
    assert.equal(ok.status, 200);
    await api(page, 'POST', `/api/ehail/rides/${rideId}/cancel`, { reason: 'changed_plans' });
    for (const c of [drv.ctx, ctx]) await c.close();
});
