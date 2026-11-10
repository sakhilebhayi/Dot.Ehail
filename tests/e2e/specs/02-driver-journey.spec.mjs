// Acceptance journey D (brief section 24, driver steps 1-14): a new driver registers, applies to a fleet, uploads every
// document through the driver app, is approved by a verification reviewer in /ops, goes online, takes a card trip from
// a passenger in another browser, is paid out in a batch drafted by finance in /ops, and sees it all in the app.
import test from 'node:test';
import assert from 'node:assert/strict';
import { newContext, watch, step, sql, one, until, interceptCheckout, makePerson, world, api, sleep, shot, externalHosts, closeBrowser, uniq, fixtureJpeg } from '../lib/harness.mjs';
import { register, addCard, priceTrip, requestCategory, paxPin, goOnline, waitOffer, accept, driveTo, arrive, startTrip, complete, ledgerBalanced, accountBalance } from '../lib/flows.mjs';
import { PICKUP, DEST, DRIVER_START } from '../lib/geo.mjs';

test.before(() => world('offline-all'));
test.after(async () => { world('offline-all'); await closeBrowser(); });

const money = (minor) => `R${(minor / 100).toFixed(2)}`;

test('driver journey: register, documents, verification, online, trip, earnings, payout', async () => {
    const owner = makePerson('fleet-owner', 'Owner', { fleet: 'Mother City Cabs' });
    const reviewer = makePerson('operator', 'Reviewer', { roles: ['verification_reviewer'] });
    const finance = makePerson('operator', 'Finance', { roles: ['finance'] });

    const dctx = await newContext({ geo: DRIVER_START });
    const d = await dctx.newPage();
    const dw = watch(d);
    const email = `${uniq('sipho')}@example.test`;

    // 1. Register.
    const driver = await step(d, 'register', () => register(d, { name: 'Sipho Ndlovu', email }));

    // 2. Apply (fleet, licence and ID, the vehicle).
    await step(d, 'apply', async () => {
        await d.goto('/drive');
        await d.getByRole('link', { name: /apply/i }).first().click();
        await d.fill('input[name=license_number]', 'E2E-LIC-55021');
        await d.fill('input[name=id_number]', '9203155009081');
        await d.check('input[name=mode][value=fleet]');
        await d.selectOption('select[name=existing_fleet_id]', String(owner.fleet_id));
        await d.fill('input[name=vehicle_make]', 'Toyota');
        await d.fill('input[name=vehicle_model]', 'Corolla');
        await d.fill('input[name=vehicle_year]', '2022');
        await d.fill('input[name=vehicle_color]', 'White');
        await d.fill('input[name=vehicle_plate_number]', 'CA 552 019');
        await d.selectOption('select[name=vehicle_type]', 'standard');
        await Promise.all([d.waitForURL(/\/drive\/onboarding/), d.click('button:has-text("Send application")')]);
    });
    const profile = one('select * from driver_profiles where user_id = ?', [driver.id]);
    assert.equal(profile.status, 'pending');
    assert.equal(profile.fleet_id, owner.fleet_id);
    assert.notEqual(profile.license_number, 'E2E-LIC-55021', 'licence number stored encrypted');
    const vehicle = one('select * from vehicles where driver_profile_id = ?', [profile.id]);
    assert.equal(vehicle.plate_number, 'CA 552 019');

    // The fleet owner approves the application in the fleet portal.
    const octx = await newContext({ user: owner, width: 1440, height: 900 });
    const o = await octx.newPage();
    await step(o, 'fleet owner approves', async () => {
        await o.goto('/fleet/drivers');
        await Promise.all([o.waitForLoadState('load'), o.locator(`form[action$="/applications/${profile.id}/approve"] button`).click()]);
        await until(() => one("select id from driver_profiles where id = ? and status = 'approved'", [profile.id]), { what: 'approved application' });
    });

    // Going online is refused until the documents are approved: the console says why, the API refuses.
    await step(d, 'online refused', async () => {
        await d.goto('/drive');
        assert.match(await d.locator('main').innerText(), /document/i);
        const r = await api(d, 'POST', '/api/ehail/driver/online');
        assert.ok([403, 409, 422].includes(r.status), `online refused (${r.status})`);
        assert.match(JSON.stringify(r.data), /document_missing/);
    });

    // 3. Documents through the driver app's own picker.
    const jpg = fixtureJpeg();
    await step(d, 'upload documents', async () => {
        await d.goto('/drive/documents');
        for (let i = 0; i < 20; i++) {
            const open = d.locator('div.dr-docform form[data-dr-upload], details.dr-docform[open] form[data-dr-upload]');
            if ((await open.count()) === 0) break;
            const f = open.first();
            const exp = f.locator('input[name=expires_on]');
            if (await exp.count()) await exp.fill('2027-08-31');
            await Promise.all([d.waitForEvent('load', { timeout: 20000 }), f.locator('label.dr-file:not(.dr-file--primary) input[type=file]').setInputFiles(jpg)]);
        }
    });
    const docs = sql('select * from verification_documents where user_id = ?', [driver.id]);
    assert.ok(docs.length >= 11, `${docs.length} documents uploaded`);
    assert.ok(docs.every((x) => x.status === 'pending'));
    assert.ok(docs.some((x) => x.vehicle_id === vehicle.id), 'vehicle documents belong to the vehicle');

    // 4. Verification: the reviewer approves each document in /ops (queue -> document -> Approve).
    const rctx = await newContext({ user: reviewer, width: 1440, height: 900 });
    const r = await rctx.newPage();
    const rw = watch(r);
    await step(r, 'reviewer approves', async () => {
        for (const tab of ['driver', 'vehicle']) {
            for (let i = 0; i < 15; i++) {
                await r.goto(`/ops/verification?subject=${tab}`);
                const link = r.locator('table.ops-table a[href*="/ops/verification/documents/"]').first();
                if (!(await link.count())) break;
                await link.click();
                await r.waitForURL(/documents\/\d+/);
                const form = r.locator('form[action$="/approve"]');
                const exp = form.locator('input[name=expires_on]');
                if ((await exp.count()) && !(await exp.inputValue())) await exp.fill('2027-08-31');
                await form.locator('button[type=submit]').click();
                await r.getByText('Document approved.').waitFor({ timeout: 10000 });
            }
        }
    });
    assert.equal(one("select count(*) n from verification_documents where user_id = ? and status != 'approved'", [driver.id]).n, 0, 'every document approved');
    assert.ok(one("select count(*) n from verification_reviews").n >= docs.length, 'each decision recorded');

    // 5. Vehicle approved and active.
    await d.goto('/drive/vehicles');
    assert.match(await d.locator('main').innerText(), /CA 552 019/);
    assert.equal(one('select is_active from vehicles where id = ?', [vehicle.id]).is_active, 1);

    // 6. Online.
    await step(d, 'go online', () => goOnline(d, driver.id));

    // A passenger books Economy by card.
    const pax = makePerson('user', 'Passenger');
    const pctx = await newContext({ user: pax, geo: PICKUP });
    await interceptCheckout(pctx);
    const p = await pctx.newPage();
    await step(p, 'passenger card', () => addCard(p));
    await step(p, 'passenger prices', () => priceTrip(p));
    const rideId = await step(p, 'passenger requests', () => requestCategory(p, 'Economy'));

    // 7-8. A valid request with pickup and earnings before accepting.
    const offerText = await step(d, 'offer', () => waitOffer(d));
    const offerRow = one("select * from ride_offers where ride_id = ? and driver_id = ? and status = 'pending'", [rideId, driver.id]);
    assert.ok(offerRow, 'the offer is this driver\'s');
    assert.ok(Math.abs(offerRow.pickup_distance_m - 400) < 60, `pickup distance ${offerRow.pickup_distance_m}`);
    assert.match(offerText, /Pickup 4\d\d m/);
    assert.match(offerText, /Trip 2\.4 km/);
    const estimate = await api(d, 'GET', `/drive/api/offers/${offerRow.id}/earnings`);
    assert.equal(estimate.status, 200);
    const netEstimate = estimate.data.net_minor ?? estimate.data.net?.amount_minor ?? estimate.data.driver_net_minor;
    assert.ok(Number.isInteger(netEstimate), `earnings estimate ${JSON.stringify(estimate.data).slice(0, 200)}`);
    assert.match(offerText, new RegExp(`You earn \\(estimate\\)|${money(netEstimate)}`));
    assert.ok(offerText.includes(money(netEstimate)), `offer shows ${money(netEstimate)}`);

    // 9. Accept; 10. navigate: a hand-off link to the pickup.
    await step(d, 'accept', () => accept(d));
    const nav = await d.locator('a[href*="google.com/maps/dir"]').first().getAttribute('href');
    assert.match(nav, /destination=-33\.94/, 'navigation goes to the pickup');

    // 11. Pickup: arrive, PIN.
    await step(d, 'drive to pickup', () => driveTo(dctx, d, DRIVER_START, PICKUP));
    await step(d, 'arrive', () => arrive(d));
    const pin = await paxPin(p);
    await step(d, 'start', () => startTrip(d, pin));
    const navTrip = await d.locator('a[href*="google.com/maps/dir"]').first().getAttribute('href');
    assert.match(navTrip, /destination=-33\.92/, 'navigation now goes to the drop-off');

    // 12. Complete.
    await step(d, 'drive trip', () => driveTo(dctx, d, PICKUP, DEST));
    await step(d, 'complete', () => complete(d));
    const earning = await until(() => one("select * from driver_earnings where ride_id = ? and kind = 'trip' and status = 'available'", [rideId]), { what: 'available earning', timeout: 30000 });
    const ride = one('select * from rides where id = ?', [rideId]);
    assert.equal(earning.fare_minor, ride.final_fare_minor);
    assert.equal(earning.net_minor, netEstimate, 'the estimate before accepting is what the driver earned (fare unchanged)');
    assert.equal(earning.fare_minor - earning.commission_minor - earning.deductions_minor, earning.net_minor);
    const summary = (await d.locator('[data-dr-view="summary"]').innerText()).replace(/\s+/g, ' ');
    assert.ok(summary.includes(money(earning.net_minor)), `summary shows ${money(earning.net_minor)}: ${summary}`);
    assert.ok(summary.includes(money(earning.commission_minor)), 'summary shows the commission');

    // 13. Earnings page = the ledger.
    assert.ok(ledgerBalanced());
    assert.equal(accountBalance(`driver_payable:${driver.id}`), earning.net_minor, 'driver payable in the ledger = net');
    const commissionRows = one("select coalesce(sum(e.credit_minor - e.debit_minor),0) v from ledger_entries e join ledger_journals j on j.id = e.journal_id where j.ride_id = ? and e.account = 'platform_revenue_commission'", [rideId]);
    assert.equal(commissionRows.v, earning.commission_minor, 'commission in the ledger = the earning\'s commission');
    await d.goto('/drive/earnings');
    const earningsText = (await d.locator('main').innerText()).replace(/\s+/g, ' ');
    assert.ok(earningsText.includes(money(earning.net_minor)), 'earnings page shows the net');
    assert.ok(earningsText.includes(money(earning.fare_minor)), 'earnings page shows the gross fare');

    // 14. Payout: the driver adds a bank account; the earning is a day old (time travel); finance drafts the batch in /ops.
    await step(d, 'bank account', async () => {
        await d.goto('/drive/earnings/payouts');
        const form = d.locator('form[action$="/bank-account"]');
        if (!(await form.isVisible())) await d.locator('.dr-more summary, details summary').filter({ hasText: /account/i }).first().click();
        await form.locator('input[name=account_holder]').fill('Sipho Ndlovu');
        await form.locator('input[name=bank_name]').fill('FNB');
        await form.locator('input[name=bank_code]').fill('250655');
        await form.locator('input[name=account_number]').fill('62000000001');
        await Promise.all([d.waitForLoadState('load'), form.locator('button[type=submit]').click()]);
        const verify = d.locator('form[action*="/bank-account/"][action$="/verify"]');
        if (await verify.count()) {
            await verify.locator('input[name=document_number]').fill('9203155009081');
            await Promise.all([d.waitForLoadState('load'), verify.locator('button[type=submit]').click()]);
        }
    });
    assert.ok(one('select id from payout_accounts where user_id = ?', [driver.id]), 'bank account saved');
    world('shift', { table: 'driver_earnings', id: earning.id, columns: ['earned_at', 'available_at', 'created_at'], seconds: 86400 });

    const fctx = await newContext({ user: finance, width: 1440, height: 900 });
    const f = await fctx.newPage();
    const fw = watch(f);
    f.on('dialog', (dialog) => dialog.accept());
    await step(f, 'draft batch', async () => {
        await f.goto('/ops/finance/payouts');
        await f.locator('form[action$="/payout-batches"] button[type=submit], form[data-ops-done="Batch drafted."] button[type=submit]').first().click();
        await f.getByText('Batch drafted.').waitFor({ timeout: 15000 });
    });
    const payout = await until(() => one('select * from payouts where payee_user_id = ?', [driver.id]), { what: 'payout row' });
    assert.equal(payout.earnings_minor, earning.net_minor, 'the batch pays the trip\'s net');
    assert.equal(one('select payout_id from driver_earnings where id = ?', [earning.id]).payout_id, payout.id, 'the trip is in the payout');
    await step(f, 'mark paid', async () => {
        await f.goto(`/ops/finance/payouts/${payout.payout_batch_id}`);
        await f.locator('form[action$="/mark-paid"] button[type=submit]').first().click();
        await f.getByText('Batch marked paid.').waitFor({ timeout: 15000 });
    });
    assert.equal(one('select status from payouts where id = ?', [payout.id]).status, 'paid');
    assert.equal(accountBalance(`driver_payable:${driver.id}`), 0, 'nothing left owed after the payout');
    assert.ok(ledgerBalanced());

    await step(d, 'driver sees the payout', async () => {
        await d.goto('/drive/earnings/payouts');
        const text = (await d.locator('main').innerText()).replace(/\s+/g, ' ');
        assert.ok(text.includes(money(payout.amount_minor)), 'payout listed');
        await d.locator(`a[href$="/drive/earnings/payouts/${payout.id}"]`).first().click();
        const detail = (await d.locator('main').innerText()).replace(/\s+/g, ' ');
        assert.ok(detail.includes(money(earning.net_minor)), 'the trip earning is in the payout');
        assert.match(detail, /paid/i);
    });

    await shot(d, 'driver-journey-done');
    for (const [who, w] of [['driver', dw], ['reviewer', rw], ['finance', fw]]) {
        assert.deepEqual(w.bad, [], `${who}: no server errors`);
        assert.deepEqual(w.errors.filter((e) => !/status of (401|403|404|409|419|422|429)/.test(e)), [], `${who}: no console errors`);
        assert.deepEqual(externalHosts(w.requests), [], `${who}: no third-party hosts`);
        assert.deepEqual(w.csp, [], `${who}: no CSP violations`);
    }
    for (const c of [dctx, octx, rctx, pctx, fctx]) await c.close();
});
