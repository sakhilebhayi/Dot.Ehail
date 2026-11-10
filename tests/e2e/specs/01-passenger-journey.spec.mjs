// Acceptance journey P (brief section 24, passenger steps 1-14) in real browsers: a passenger (390 px phone) and a
// verified driver (second context, emulated GPS) on the integrated app; only the providers are stubs.
import test from 'node:test';
import assert from 'node:assert/strict';
import { newContext, watch, step, sql, one, until, stub, interceptCheckout, makePerson, world, api, sleep, shot, externalHosts, closeBrowser, uniq } from '../lib/harness.mjs';
import { register, addCard, searchPlace, requestCategory, paxTitle, paxPin, goOnline, waitOffer, accept, driveTo, arrive, startTrip, complete, ledgerBalanced } from '../lib/flows.mjs';
import { PICKUP, DEST, DRIVER_START, roadPath, pathMetres } from '../lib/geo.mjs';

test.before(() => world('offline-all'));
test.after(async () => { world('offline-all'); await closeBrowser(); });

test('passenger journey: register to rating, paid by card', async () => {
    const driver = makePerson('verified-driver', 'Driver');
    const dctx = await newContext({ user: driver, geo: DRIVER_START });
    const d = await dctx.newPage();
    const dw = watch(d);
    await goOnline(d, driver.id);

    const pctx = await newContext({ geo: PICKUP });
    await interceptCheckout(pctx);
    const p = await pctx.newPage();
    const pw = watch(p);
    const email = `${uniq('thandi')}@example.test`;

    // 1. Register.
    const pax = await step(p, 'register', () => register(p, { name: 'Thandi Mokoena', email }));

    // Payment method: a card through Paystack's checkout (stub), the R1.00 check refunded.
    await step(p, 'add card', () => addCard(p));
    const card = one("select * from payment_methods where user_id = ? and status = 'active'", [pax.id]);
    assert.equal(card.last4, '4081');
    assert.equal(card.is_default, 1);
    const setup = one("select * from ride_payments where user_id = ? and purpose = 'card_setup'", [pax.id]);
    assert.equal(setup.status, 'paid');
    await until(async () => (await stub()).paystack.calls.some((c) => c.path === '/refund' && c.body.transaction === setup.reference), { what: 'the R1.00 refund' });

    // 2. Pickup from the phone's location; 3. destination by search.
    await step(p, 'pickup by location', async () => {
        await p.goto('/ride?new=1');
        await p.click('[data-pax-locate]');
        await until(async () => (await p.locator('#pax-pickup').inputValue()).length > 0, { what: 'pickup filled from location' });
    });
    await step(p, 'destination by search', () => searchPlace(p, '#pax-dropoff', 'Bree'));

    // 4. Estimate from the (stub) road route; 5. transparent breakdown.
    await step(p, 'see prices', async () => {
        await p.click('[data-pax-see-prices]');
        await p.locator('.pax-option').first().waitFor({ timeout: 20000 });
    });
    const roadM = pathMetres(roadPath(PICKUP, DEST));
    const quotes = sql('select * from fare_quotes where passenger_id = ? order by created_at desc', [pax.id]);
    assert.ok(quotes.length >= 1, 'quotes stored for the passenger');
    for (const q of quotes) {
        assert.ok(Math.abs(q.route_distance_m - roadM) < 40, `quote distance ${q.route_distance_m} m = the road route ${Math.round(roadM)} m (the pickup came from the phone, a few metres off)`);
        assert.equal(q.route_provider, 'osrm');
        const components = JSON.parse(q.components);
        const sum = components.reduce((s, c) => s + c.amount_minor, 0);
        assert.equal(sum, q.total_minor, `components sum to the total (${q.id})`);
    }
    const tripLine = await p.locator('.pax-trip, [data-pax-trip]').first().innerText();
    assert.match(tripLine, /2\.4 km/, 'the sheet shows the road distance');

    const economy = p.locator('.pax-option', { has: p.locator('.pax-option__name, h2, h3', { hasText: 'Economy' }) }).first();
    await economy.locator('.pax-option__pick').check();
    await economy.locator('.pax-option__more').click();
    const lines = await economy.locator('.pax-fare dd').allInnerTexts();
    const amounts = lines.map((t) => t.replace(/\s/g, '')).filter((t) => /^[−-]?R\d/.test(t)).map((t) => Math.round(Number(t.replace(/[R,−-]/g, '')) * 100) * (/^[−-]/.test(t) ? -1 : 1));
    assert.ok(amounts.length >= 4, `breakdown lines read: ${lines.join(' | ')}`);
    const total = amounts.pop();
    assert.equal(amounts.reduce((a, b) => a + b, 0), total, `the visible lines add up to the visible total: ${lines.join(' | ')}`);
    const ecoQuote = quotes.find((q) => q.total_minor === total);
    assert.ok(ecoQuote, 'the visible Economy total is a stored quote total');

    // 6. Choose and request (card is the default choice).
    assert.match(await p.locator('[data-pax-payment]').innerText(), /Visa •••• 4081/);
    assert.match(await p.locator('[data-pax-request]').innerText(), new RegExp(`Request Economy, R${(total / 100).toFixed(2)}`));
    const rideId = await step(p, 'request', () => requestCategory(p, 'Economy'));
    const ride = one('select * from rides where id = ?', [rideId]);
    assert.equal(ride.passenger_id, pax.id);
    assert.equal(ride.quoted_total_minor, total);
    assert.equal(ride.payment_method_kind, 'card');
    assert.ok(one('select consumed_at from fare_quotes where ride_id = ?', [rideId]).consumed_at, 'quote bound to the ride');

    // 7. A real driver is assigned (offer in the driver app, accepted there).
    const offer = await step(d, 'offer', () => waitOffer(d));
    assert.match(offer, new RegExp(`R${(total / 100).toFixed(2)}`), 'the offer shows the fare');
    assert.match(offer, /You earn/);
    await step(d, 'accept', () => accept(d));
    await step(p, 'assigned', () => paxTitle(p, /on the way/i));
    assert.equal(one('select driver_id from rides where id = ?', [rideId]).driver_id, driver.id);
    assert.match(await p.locator('main').innerText(), /White Toyota Corolla/);

    // 8. Track the driver: positions from the driver's phone reach the passenger's feed and page.
    await step(d, 'drive to pickup', () => driveTo(dctx, d, DRIVER_START, PICKUP));
    const pos = await until(async () => {
        const feed = await api(p, 'GET', `/api/ehail/rides/${rideId}/feed`);
        const at = feed.data?.driver_position;
        return at && Math.abs(at.lat - PICKUP[0]) < 0.0005 && Math.abs(at.lng - PICKUP[1]) < 0.0005 ? at : null;
    }, { what: 'the passenger feed showing the driver at the pickup', every: 500 });
    assert.ok(Math.abs(pos.lat - PICKUP[0]) < 0.0005 && Math.abs(pos.lng - PICKUP[1]) < 0.0005, `driver shown at the pickup (${pos.lat}, ${pos.lng})`);
    assert.ok(sql('select count(*) n from driver_locations where driver_id = ?', [driver.id])[0].n === 1);

    // 9. Pickup and PIN.
    await step(d, 'arrive', () => arrive(d));
    await step(p, 'driver is here', () => paxTitle(p, /is here/i));
    const pin = await paxPin(p);
    assert.match(pin, /^\d{4}$/);
    await step(d, 'start with PIN', () => startTrip(d, pin));
    await step(p, 'trip under way', () => paxTitle(p, /On the way to/i));

    // 10. Follow the trip; 11. complete.
    await step(d, 'drive the trip', () => driveTo(dctx, d, PICKUP, DEST));
    assert.ok(one('select count(*) n from trip_points where ride_id = ?', [rideId]).n >= 10, 'the trail was recorded');
    await step(d, 'complete', () => complete(d));

    // 12. Paid by card (charge_authorization on the stub, verified, signed webhook de-duplicated).
    const paid = await until(() => one("select * from ride_payments where ride_id = ? and purpose = 'ride_fare' and status = 'paid'", [rideId]), { what: 'card payment', timeout: 30000 });
    const done = one('select * from rides where id = ?', [rideId]);
    assert.equal(done.status, 'completed');
    assert.equal(paid.amount_cents, done.final_fare_minor);
    const fare = one('select * from ride_fares where ride_id = ?', [rideId]);
    assert.equal(fare.basis, 'quoted_fare_stands');
    assert.equal(fare.final_total_minor, total);
    const charges = (await stub()).paystack.calls.filter((c) => c.path === '/transaction/charge_authorization' && c.body.reference === paid.reference);
    assert.equal(charges.length, 1, 'charged exactly once');
    await until(async () => (await stub()).webhooks.some((w) => w.reference === paid.reference && w.status === 200), { what: 'the charge.success webhook' });
    assert.equal(one("select count(*) n from ride_payments where ride_id = ? and purpose = 'ride_fare'", [rideId]).n, 1, 'the webhook created no second payment');
    assert.ok(ledgerBalanced(), 'every journal balances');

    // 13. Receipt on the trip page and its own page.
    await step(p, 'completed page', () => paxTitle(p, /arrived/i, 40000));
    const receipt = await until(() => one('select * from receipts where ride_id = ?', [rideId]), { what: 'receipt' });
    assert.equal(receipt.total_minor, total);
    await p.locator('[data-pax-receipt]:not([hidden])').waitFor({ timeout: 20000 });
    const receiptPage = await p.context().newPage();
    await receiptPage.goto(await p.locator('[data-pax-receipt]').getAttribute('href'));
    const receiptText = await receiptPage.locator('main, body').first().innerText();
    assert.match(receiptText, new RegExp(receipt.number));
    assert.match(receiptText, new RegExp(`R\\s?${(total / 100).toFixed(2)}`));
    assert.match(receiptText, /4081/);
    await receiptPage.close();
    const mail = await until(async () => (await stub()).mails.find((m) => m.to.includes(email) && /receipt/i.test(m.subject)), { what: 'receipt email' });
    assert.ok(mail);

    // 14. Rate.
    await step(p, 'rate', async () => {
        await p.locator('label[for="pax-star-5"]').click();
        await p.locator('[data-tag="Safe driving"]').click();
        await p.locator('[data-pax-rate] textarea').fill('Careful and on time.');
        await p.locator('[data-pax-rate] button[type=submit]').click();
        await p.locator('[data-pax-rated]:not([hidden])').waitFor();
    });
    const rating = one('select * from ride_ratings where ride_id = ? and rated_by = ?', [rideId, pax.id]);
    assert.equal(rating.rating, 5);
    assert.equal(rating.rated_user_id, driver.id);

    await shot(p, 'passenger-journey-done');
    for (const [who, w] of [['passenger', pw], ['driver', dw]]) {
        assert.deepEqual(w.bad, [], `${who}: no server errors`);
        assert.deepEqual(w.errors.filter((e) => !/status of (401|403|404|409|419|422|429)/.test(e)), [], `${who}: no console errors`);
        assert.deepEqual(externalHosts(w.requests), [], `${who}: no third-party hosts`);
        assert.deepEqual(w.csp, [], `${who}: no CSP violations`);
    }
    await dctx.close();
    await pctx.close();
});
