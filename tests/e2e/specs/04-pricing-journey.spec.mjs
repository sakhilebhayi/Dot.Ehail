// Acceptance journey F (brief section 24, pricing): the public estimate (no drivers needed, supply not promised),
// server recomputation (a client total is ignored), the breakdown, booking, final reconciliation for a passenger's
// destination change (higher fare with its reason) and for a delay within tolerance (fare unchanged), the driver's
// earnings and platform commission in the ledger, and receipts and statements that agree.
import test from 'node:test';
import assert from 'node:assert/strict';
import { browser, env, newContext, watch, step, sql, one, until, interceptCheckout, makePerson, world, api, sleep, shot, externalHosts, closeBrowser } from '../lib/harness.mjs';
import { addCard, priceTrip, requestCategory, paxTitle, paxPin, goOnline, waitOffer, accept, driveTo, arrive, startTrip, complete, searchPlace, ledgerBalanced } from '../lib/flows.mjs';
import { PICKUP, DEST, FURTHER_EAST, DRIVER_START } from '../lib/geo.mjs';

test.before(() => world('offline-all'));
test.after(async () => { world('offline-all'); await closeBrowser(); });

const rand = (minor) => `R${(minor / 100).toFixed(2)}`;
const lineSum = (fareJson) => JSON.parse(fareJson).reduce((s, c) => s + c.amount_minor, 0);

test('public estimate works with no driver online and says supply is not guaranteed', async () => {
    assert.equal(one("select count(*) n from driver_availability where state in ('online','paused')").n, 0, 'nobody online');
    const ctx = await (await browser()).newContext({ baseURL: env.base, ignoreHTTPSErrors: true, viewport: { width: 390, height: 844 }, geolocation: { latitude: PICKUP[0], longitude: PICKUP[1], accuracy: 8 }, permissions: ['geolocation'] });
    const g = await ctx.newPage();
    const gw = watch(g);
    await g.goto('/fares');
    await g.click('[data-pax-locate]');
    await g.locator('[data-pax-calc="pickup"][data-empty="false"]').waitFor();
    const box = await g.locator('.eh-mapframe__canvas, [data-eh-map-canvas]').first().boundingBox();
    await g.mouse.click(box.x + box.width * 0.7, box.y + box.height * 0.2);
    const out = g.locator('[data-pax-estimate]');
    await out.locator('.pax-option').first().waitFor({ timeout: 20000 });
    const text = (await out.innerText()).replace(/\s+/g, ' ');
    assert.match(text, /Economy/);
    assert.match(text, /not guaranteed/i, 'the page says a driver is not guaranteed');
    assert.doesNotMatch(text, /No .* drivers are available/);
    assert.equal(one('select count(*) n from fare_quotes').n >= 0, true);
    assert.equal(one("select count(*) n from fare_quotes where passenger_id is null").n, 0, 'nothing stored for the guest');
    // Every estimate card adds up.
    await out.locator('.pax-option__more').first().click();
    const amounts = (await out.locator('.pax-option').first().locator('.pax-fare dd').allInnerTexts()).map((t) => t.replace(/\s/g, '')).filter((t) => /^[−-]?R\d/.test(t)).map((t) => Math.round(Number(t.replace(/[R,−-]/g, '')) * 100) * (/^[−-]/.test(t) ? -1 : 1));
    const total = amounts.pop();
    assert.equal(amounts.reduce((a, b) => a + b, 0), total);
    assert.deepEqual(gw.bad, []);
    assert.deepEqual(externalHosts(gw.requests), []);
    assert.deepEqual(gw.csp, []);
    await ctx.close();
});

test('pricing journey: recomputation, destination change, delay within tolerance, ledger, receipts, statements', async () => {
    const pax = makePerson('user', 'Passenger');
    const driver = makePerson('verified-driver', 'Driver');
    const dctx = await newContext({ user: driver, geo: DRIVER_START });
    const d = await dctx.newPage();
    const dw = watch(d);
    await goOnline(d, driver.id);
    const pctx = await newContext({ user: pax, geo: PICKUP });
    await interceptCheckout(pctx);
    const p = await pctx.newPage();
    const pw = watch(p);
    await addCard(p);

    // Server recomputation: a client-sent total is ignored by the quote and by the booking.
    await priceTrip(p);
    const tampered = await api(p, 'POST', '/pricing/quotes', { pickup_lat: PICKUP[0], pickup_lng: PICKUP[1], dropoff_lat: DEST[0], dropoff_lng: DEST[1], category: 'standard', total_minor: 100, total: 1, fare: { total_minor: 100 } });
    assert.equal(tampered.status, 200);
    const honest = one("select total_minor from fare_quotes where id = ?", [tampered.data.quote_id]);
    assert.ok(honest.total_minor > 100, 'the quote is priced by the server');
    assert.equal(tampered.data.fare.total.amount_minor, honest.total_minor);
    const booked = await api(p, 'POST', '/api/ehail/rides', { quote_id: tampered.data.quote_id, quoted_total_minor: 100, final_fare_minor: 100, estimated_fare: 1, pickup_address: 'Kloof Street', dropoff_address: 'Bree Street' }, { 'Idempotency-Key': `tamper-${Date.now()}` });
    assert.equal(booked.status, 201, JSON.stringify(booked.data).slice(0, 300));
    const ride1 = booked.data.ride.id;
    assert.equal(one('select payment_method_kind from rides where id = ?', [ride1]).payment_method_kind, 'card', 'no method sent: the saved default card');
    assert.equal(one('select quoted_total_minor from rides where id = ?', [ride1]).quoted_total_minor, honest.total_minor, 'the ride carries the server price');
    const snap = one('select * from ride_fares where ride_id = ?', [ride1]);
    assert.equal(lineSum(snap.estimated_components), snap.estimated_total_minor, 'the bound snapshot adds up');

    // Trip 1: the passenger changes the destination mid-trip -> higher fare with the reason.
    await p.goto(`/ride/trips/${ride1}`);
    await waitOffer(d);
    await accept(d);
    await driveTo(dctx, d, DRIVER_START, PICKUP);
    await arrive(d);
    await startTrip(d, await paxPin(p));
    await paxTitle(p, /On the way to/i);
    await step(p, 'change destination', async () => {
        await p.locator('[data-pax-change] summary').click();
        await searchPlace(p, '#pax-change', 'Harbour');
        await p.locator('[data-pax-change-confirm]').click();
        await p.locator('[data-pax-change-status]').filter({ hasText: /./ }).waitFor({ timeout: 15000 });
    });
    assert.ok(Math.abs(one('select dropoff_lat from rides where id = ?', [ride1]).dropoff_lat - FURTHER_EAST[0]) < 0.0005, 'the ride now goes to the new destination');
    await driveTo(dctx, d, PICKUP, FURTHER_EAST);
    await complete(d);
    const fare1 = await until(() => one('select * from ride_fares where ride_id = ? and finalised_at is not null', [ride1]), { what: 'finalised fare' });
    assert.equal(fare1.basis, 'recalculated');
    assert.ok(fare1.final_total_minor > fare1.estimated_total_minor, `higher fare ${fare1.final_total_minor} > ${fare1.estimated_total_minor}`);
    const adj = JSON.parse(fare1.adjustments);
    assert.ok(adj.some((a) => a.code === 'destination_changed' || a.reason_code === 'destination_changed'), `reason recorded: ${fare1.adjustments}`);
    assert.equal(adj.reduce((s, a) => s + (a.amount_minor ?? 0), 0), fare1.final_total_minor - fare1.estimated_total_minor, 'adjustments sum to the difference');
    assert.equal(lineSum(fare1.final_components), fare1.final_total_minor, 'final lines add up');
    await paxTitle(p, /arrived/i, 40000);
    await p.getByText(/changed the destination/i).first().waitFor({ timeout: 20000 });
    const pay1 = await until(() => one("select * from ride_payments where ride_id = ? and purpose = 'ride_fare' and status = 'paid'", [ride1]), { what: 'payment 1' });
    assert.equal(pay1.amount_cents, fare1.final_total_minor, 'the card was charged the final fare');

    // Trip 2: a delay within tolerance (+20 % time, tolerance 25 %) keeps the quoted fare.
    await priceTrip(p);
    const ride2 = await requestCategory(p, 'Economy');
    await waitOffer(d);
    await accept(d);
    await d.locator('[data-dr-view="trip"]').waitFor();
    await driveTo(dctx, d, PICKUP, PICKUP); // already at the pickup
    await arrive(d);
    await startTrip(d, await paxPin(p));
    await driveTo(dctx, d, PICKUP, DEST);
    const quoted = one('select route_duration_s from rides where id = ?', [ride2]).route_duration_s;
    const elapsed = Math.round((Date.now() - Date.parse(one('select started_at from rides where id = ?', [ride2]).started_at.replace(' ', 'T') + 'Z')) / 1000);
    world('shift', { table: 'rides', id: ride2, columns: ['started_at'], seconds: Math.max(0, Math.round(quoted * 1.2) - elapsed) });
    await complete(d);
    const fare2 = await until(() => one('select * from ride_fares where ride_id = ? and finalised_at is not null', [ride2]), { what: 'finalised fare 2' });
    assert.ok(fare2.actual_duration_s > quoted * 1.1 && fare2.actual_duration_s <= quoted * 1.25, `delay ${fare2.actual_duration_s}s vs quoted ${quoted}s`);
    assert.equal(fare2.basis, 'quoted_fare_stands');
    assert.equal(fare2.final_total_minor, fare2.estimated_total_minor, 'fare unchanged');

    // Ledger: per ride, commission and fees revenue equal the driver's earning split; every journal balances.
    for (const rideId of [ride1, ride2]) {
        const e = await until(() => one("select * from driver_earnings where ride_id = ? and kind = 'trip'", [rideId]), { what: 'earning' });
        const f = one('select * from ride_fares where ride_id = ?', [rideId]);
        assert.equal(e.fare_minor, f.final_total_minor);
        assert.equal(e.fare_minor - e.commission_minor - e.deductions_minor, e.net_minor);
        const credit = (acct) => one("select coalesce(sum(e.credit_minor - e.debit_minor),0) v from ledger_entries e join ledger_journals j on j.id = e.journal_id where j.ride_id = ? and j.kind = 'trip_revenue' and e.account = ?", [rideId, acct]).v;
        assert.equal(credit('platform_revenue_commission'), e.commission_minor, 'commission in the ledger');
        assert.equal(credit(`driver_payable:${driver.id}`), e.net_minor, 'driver payable in the ledger');
        assert.equal(credit('platform_revenue_commission') + credit('platform_revenue_fees') + credit(`driver_payable:${driver.id}`) + credit('tax_payable'), f.final_total_minor, 'the fare is fully split');
    }
    assert.ok(ledgerBalanced());

    // Receipts and statements agree with the fares and payments.
    const pay2 = await until(() => one("select * from ride_payments where ride_id = ? and purpose = 'ride_fare' and status = 'paid'", [ride2]), { what: 'payment 2' });
    for (const [rideId, pay] of [[ride1, pay1], [ride2, pay2]]) {
        const r = await until(() => one('select * from receipts where ride_id = ?', [rideId]), { what: 'receipt' });
        assert.equal(r.total_minor, pay.amount_cents);
        const page = await pctx.newPage();
        await page.goto(`/money/receipts/${r.id}`);
        const body = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
        assert.ok(body.includes(rand(r.total_minor)), 'receipt page total');
        if (rideId === ride1) assert.match(body, /destination/i, 'the receipt says why the fare changed');
        await page.close();
    }
    const csv = await d.evaluate(async () => (await fetch('/money/driver/earnings.csv?period=day', { credentials: 'same-origin' })).text());
    for (const rideId of [ride1, ride2]) {
        const e = one("select * from driver_earnings where ride_id = ? and kind = 'trip'", [rideId]);
        assert.ok(csv.includes(String(rideId)), `statement lists ride ${rideId}`);
        assert.ok(csv.includes((e.net_minor / 100).toFixed(2)), `statement shows net ${e.net_minor}`);
    }
    await d.goto('/drive/earnings');
    const totalNet = one("select sum(net_minor) v from driver_earnings where driver_id = ?", [driver.id]).v;
    assert.ok((await d.locator('main').innerText()).includes(rand(totalNet)), `earnings page total ${rand(totalNet)}`);

    for (const [who, w] of [['passenger', pw], ['driver', dw]]) {
        assert.deepEqual(w.bad, [], `${who}: no server errors`);
        assert.deepEqual(w.errors.filter((e) => !/status of (401|403|404|409|419|422|429)/.test(e)), [], `${who}: no console errors`);
        assert.deepEqual(w.csp, [], `${who}: no CSP violations`);
    }
    await shot(p, 'pricing-journey-done');
    await dctx.close();
    await pctx.close();
});
