// Acceptance journey S (brief section 24, safety): a trusted contact confirmed by an emailed link, SOS during a trip
// from the passenger's phone, the incident with trip and location context, the contact's email (captured by the SMTP
// stub) and SMS (SMS stub), the ops console, escalation after the critical timer (time moved back in the database,
// then the scheduler's sweep, as cron runs it), acknowledgement and resolution, and a "what we did" that matches the
// notification rows.
import test from 'node:test';
import assert from 'node:assert/strict';
import { browser, env, newContext, watch, step, sql, one, until, stub, interceptCheckout, makePerson, world, artisan, sleep, shot, externalHosts, closeBrowser, uniq } from '../lib/harness.mjs';
import { addCard, priceTrip, requestCategory, paxTitle, paxPin, goOnline, waitOffer, accept, driveTo, arrive, startTrip } from '../lib/flows.mjs';
import { PICKUP, DEST, DRIVER_START } from '../lib/geo.mjs';

test.before(() => world('offline-all'));
test.after(async () => { world('offline-all'); await closeBrowser(); });

test('safety journey: SOS mid-trip to resolution', async () => {
    const pax = makePerson('user', 'Thandi');
    const driver = makePerson('verified-driver', 'Driver');
    const officer = makePerson('operator', 'Safety', { roles: ['safety_officer'] });
    const contactEmail = `${uniq('naledi')}@example.test`;

    const pctx = await newContext({ user: pax, geo: PICKUP });
    await interceptCheckout(pctx);
    const p = await pctx.newPage();
    const pw = watch(p);

    // A trusted contact, confirmed through the emailed link (GET shows a button, POST confirms).
    await step(p, 'add contact', async () => {
        await p.goto('/ride/safety');
        const form = p.locator('[data-pax-contact-form]');
        await form.locator('#contact-name').fill('Naledi Mokoena');
        await form.locator('#contact-email').fill(contactEmail);
        await form.locator('#contact-phone').fill('+27821234567');
        await form.locator('#contact-relationship').fill('Sister');
        await form.locator('button[type=submit]').click();
        await p.locator('[data-pax-contacts]').getByText('Naledi Mokoena').waitFor({ timeout: 10000 });
    });
    const invite = await until(async () => (await stub()).mails.find((m) => m.to.includes(contactEmail)), { what: 'confirmation email' });
    const confirmUrl = invite.body.match(/https?:\/\/[^\s"'<>]+\/safety\/contact\/confirm\/[A-Za-z0-9_-]+/)?.[0];
    assert.ok(confirmUrl, 'the email carries the confirmation link');
    const anon = await (await browser()).newContext({ baseURL: env.base });
    const a = await anon.newPage();
    await a.goto(confirmUrl.replace(/^https?:\/\/[^/]+/, ''));
    assert.equal(one('select confirmed_at from trusted_contacts where user_id = ?', [pax.id]).confirmed_at, null, 'opening the link alone confirms nothing');
    await Promise.all([a.waitForLoadState('load'), a.getByRole('button', { name: /yes, i agree/i }).click()]);
    assert.ok(one('select confirmed_at from trusted_contacts where user_id = ?', [pax.id]).confirmed_at, 'confirmed by the contact');

    // A trip under way.
    const dctx = await newContext({ user: driver, geo: DRIVER_START });
    const d = await dctx.newPage();
    const dw = watch(d);
    await goOnline(d, driver.id);
    await step(p, 'card', () => addCard(p));
    await step(p, 'book', () => priceTrip(p));
    const rideId = await requestCategory(p, 'Economy');
    await waitOffer(d);
    await accept(d);
    await driveTo(dctx, d, DRIVER_START, PICKUP);
    await arrive(d);
    await startTrip(d, await paxPin(p));
    await paxTitle(p, /On the way to/i);
    // Part of the way: north along the pickup's street.
    await driveTo(dctx, d, PICKUP, [-33.934, 18.41]);

    // SOS: one tap, a 3-second window, then "what we did".
    const mailsBefore = (await stub()).mails.length;
    await step(p, 'sos', async () => {
        await p.click('[data-pax-sos-open]');
        await p.click('[data-sos-start]');
        await p.waitForURL(/\/ride\/safety\/incidents\/\d+/, { timeout: 20000 });
    });
    const incidentId = Number(p.url().match(/incidents\/(\d+)/)[1]);
    const incident = one('select * from safety_incidents where id = ?', [incidentId]);
    assert.equal(incident.ride_id, rideId, 'tied to the trip');
    assert.equal(incident.reporter_id, pax.id);
    assert.equal(incident.severity, 'critical');
    assert.equal(incident.status, 'open');
    const context = JSON.parse(incident.context);
    const ctxText = JSON.stringify(context);
    assert.match(ctxText, /in_progress/, 'ride state captured');
    assert.match(ctxText, /CA \d+/, 'vehicle registration captured');
    assert.match(ctxText, /-33\.93/, 'driver position captured');

    // The contact got an email with a live share link; an SMS too (provider configured).
    const alert = await until(async () => (await stub()).mails.slice(mailsBefore).find((m) => m.to.includes(contactEmail)), { what: 'alert email to the contact' });
    const shareUrl = alert.body.match(/https?:\/\/[^\s"'<>]+\/t\/[A-Za-z0-9_-]{20,}/)?.[0];
    assert.ok(shareUrl, 'the alert carries a live trip link');
    assert.match(alert.body, /10111/, 'emergency numbers in the alert');
    const sms = await until(async () => (await stub()).sms.find((m) => m.to === '+27821234567'), { what: 'SMS to the contact' });
    assert.ok(sms.body.length > 10);
    const share = await anon.newPage();
    await share.goto(shareUrl.replace(/^https?:\/\/[^/]+/, ''));
    assert.match(await share.locator('body').innerText(), /CA \d+/, 'the share page shows the car');

    // "What we did" = the notification rows, nothing more.
    // Rows are written pending and finished by the queued send: wait for both to be final.
    const rows = await until(() => {
        const all = sql('select * from incident_notifications where safety_incident_id = ?', [incidentId]);
        const contact = all.filter((r) => r.recipient_kind === 'trusted_contact' && ['mail', 'sms'].includes(r.channel));
        return contact.length >= 2 && contact.every((r) => !['pending', 'queued'].includes(r.status)) ? all : null;
    }, { what: 'the contact notifications to finish' });
    assert.equal(rows.find((r) => r.channel === 'mail' && r.recipient_kind === 'trusted_contact')?.status, 'sent', 'contact email sent (SMTP accepted it)');
    assert.equal(rows.find((r) => r.channel === 'sms' && r.recipient_kind === 'trusted_contact')?.status, 'sent');
    assert.ok(rows.some((r) => r.recipient_kind === 'operations' && r.channel === 'database' && r.status === 'sent'), 'operators alerted in the app');
    const whatWeDid = (await p.locator('main').innerText()).replace(/\s+/g, ' ');
    assert.match(whatWeDid, /10111/);
    assert.match(whatWeDid, /What we did not do/i, 'states what Dot.Ehail did not do');
    assert.ok((await p.locator('section[aria-labelledby="inc-not"] p').count()) >= 1, 'with at least one line');
    assert.match(whatWeDid, /Sent/);
    assert.doesNotMatch(whatWeDid, /police (have been|were) (called|notified)/i);

    // The ops console shows it.
    const octx = await newContext({ user: officer, width: 1440, height: 900 });
    const o = await octx.newPage();
    const ow = watch(o);
    o.on('dialog', (dialog) => dialog.accept());
    await step(o, 'ops sees the incident', async () => {
        await o.goto('/ops/safety');
        await o.locator(`a[href$="/ops/safety/incidents/${incidentId}"]`).first().click();
        await o.waitForURL(/\/ops\/safety\/incidents\/\d+/);
        const text = await o.locator('main').innerText();
        assert.match(text, new RegExp(incident.reference));
        assert.match(text, /Naledi|trusted contact/i);
    });

    // Nobody acknowledged within the critical timer (2 min): time moves on, the scheduler's sweep escalates.
    world('shift', { table: 'safety_incidents', id: incidentId, columns: ['created_at', 'next_escalation_at'], seconds: 180 });
    artisan('ehail:safety-sweep');
    const escalated = one('select * from safety_incidents where id = ?', [incidentId]);
    assert.equal(escalated.escalation_level, 1, 'escalated to the on-call lead');
    await until(async () => (await stub()).mails.find((m) => m.to.includes('oncall@ehail.example.test')), { what: 'on-call email' });
    assert.ok(one("select id from incident_events where safety_incident_id = ? and type like '%escalat%'", [incidentId]), 'escalation on the timeline');

    // Acknowledge, then resolve with an outcome.
    await step(o, 'acknowledge', async () => {
        await o.reload();
        await o.locator('form[action$="/acknowledge"] button[type=submit]').click();
        await o.getByText(/Acknowledged/).first().waitFor({ timeout: 10000 });
    });
    assert.ok(one('select acknowledged_at from safety_incidents where id = ?', [incidentId]).acknowledged_at);
    await step(o, 'resolve', async () => {
        await o.reload();
        await o.locator('summary', { hasText: 'Resolve' }).click();
        const form = o.locator('form[action$="/resolve"]');
        await form.locator('select[name=resolution_code]').selectOption('user_safe');
        await form.locator('textarea[name=note]').fill('Spoke to the passenger; she is safe at home.');
        await form.locator('button[type=submit]').click();
        await o.getByText('Incident resolved.').waitFor({ timeout: 10000 });
    });
    const resolved = one('select * from safety_incidents where id = ?', [incidentId]);
    assert.equal(resolved.status, 'resolved');
    assert.equal(resolved.resolution_code, 'user_safe');
    const types = sql('select type from incident_events where safety_incident_id = ? order by id', [incidentId]).map((r) => r.type);
    assert.ok(types.length >= 4, `timeline: ${types.join(', ')}`);

    // The passenger's "what we did" now says it is resolved, and still lists only what happened.
    await p.reload();
    const after = (await p.locator('main').innerText()).replace(/\s+/g, ' ');
    assert.match(after, /resolved/i);
    const sentRows = sql("select count(*) n from incident_notifications where safety_incident_id = ? and status = 'sent'", [incidentId])[0].n;
    assert.ok(sentRows >= 2);

    await shot(p, 'safety-journey-done');
    for (const [who, w] of [['passenger', pw], ['driver', dw], ['ops', ow]]) {
        assert.deepEqual(w.bad, [], `${who}: no server errors`);
        assert.deepEqual(w.errors.filter((e) => !/status of (401|403|404|409|419|422|429)/.test(e)), [], `${who}: no console errors`);
        assert.deepEqual(externalHosts(w.requests), [], `${who}: no third-party hosts`);
        assert.deepEqual(w.csp, [], `${who}: no CSP violations`);
    }
    for (const c of [pctx, dctx, octx, anon]) await c.close();
});
