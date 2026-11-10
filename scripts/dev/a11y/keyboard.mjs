// R-46 a11y: keyboard-only checks in real Chrome (real key events). Every check prints PASS/FAIL with what it saw.
//   node scripts/dev/a11y/keyboard.mjs [--vp 390] [--only pages|live]
// Pages: skip link first and working, a full Tab walk that wraps without a trap, every stop named, every stop with a
// visible focus indicator and not hidden under the sticky bars (WCAG 2.4.11). Live: the booking sheet by keyboard,
// the driver offer (focus on Accept, nothing behind it reachable, countdown spoken at marks, decline reachable),
// the PIN keypad by keyboard digits, SOS opened, counted down aloud and stopped by keyboard on both apps.
import { BASE, PEOPLE, PICKUP, START, arg, browser, driverPhone, log, person, search, sleep, writeJson } from './lib.mjs';

const vp = Number(arg('vp', 390));
const only = arg('only', 'pages,live').split(',');
const results = [];
const check = (name, ok, info = '') => { results.push({ name, ok: !!ok, info }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  [' + String(info).slice(0, 220) + ']' : ''}`); };

const ACTIVE = `(() => { const a = document.activeElement; if (!a || a === document.body) return { tag: 'body' };
    const r = a.getBoundingClientRect(); const cs = getComputedStyle(a);
    const name = (a.getAttribute('aria-label') || (a.getAttribute('aria-labelledby') && document.getElementById(a.getAttribute('aria-labelledby'))?.textContent) || a.labels?.[0]?.textContent || a.getAttribute('title') || a.textContent || a.value || a.getAttribute('placeholder') || '').trim().replace(/\\s+/g, ' ');
    const ring = (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) >= 2) || (cs.boxShadow && cs.boxShadow !== 'none');
    const pts = [[r.left + r.width / 2, r.top + r.height / 2], [r.left + 3, r.top + 3], [r.right - 3, r.bottom - 3], [r.left + 3, r.bottom - 3], [r.right - 3, r.top + 3]];
    const visible = pts.some(([x, y]) => { if (x < 0 || y < 0 || x > innerWidth || y > innerHeight) return false; const hit = document.elementFromPoint(x, y); return hit && (hit === a || a.contains(hit) || hit.contains(a)); });
    return { tag: a.tagName.toLowerCase(), cls: typeof a.className === 'string' ? a.className.split(' ')[0] : '', id: a.id, name: name.slice(0, 40), ring, visible, w: r.width, h: r.height };
})()`;
const label = (a) => `${a.tag}${a.id ? '#' + a.id : ''}${a.cls ? '.' + a.cls : ''}[${a.name || ''}]`;

async function walk(page, id) {
    await page.evaluate(() => { document.activeElement?.blur(); window.scrollTo(0, 0); });
    await page.keyboard.press('Tab');
    const first = await page.evaluate(ACTIVE);
    check(`${id}: first Tab stop is the skip link`, first.cls === 'eh-skip' || /skip/i.test(first.name), label(first));
    if (first.cls === 'eh-skip') {
        await page.keyboard.press('Enter'); await sleep(150);
        const after = await page.evaluate(() => document.activeElement?.id || document.activeElement?.tagName);
        check(`${id}: skip link moves focus to the content`, after === 'main', after);
    }
    await page.evaluate(() => { document.activeElement?.blur(); window.scrollTo(0, 0); });
    const stops = [];
    for (let i = 0; i < 160; i++) {
        await page.keyboard.press('Tab');
        const a = await page.evaluate(ACTIVE);
        if (a.tag === 'body') { stops.push(a); break; }
        if (stops.length > 2 && label(a) === label(stops[0])) break;
        stops.push(a);
    }
    const real = stops.filter((s) => s.tag !== 'body');
    check(`${id}: Tab walk ends or wraps without a trap`, stops.length < 160, `${real.length} stops`);
    const unnamed = real.filter((s) => !s.name && !['input', 'select', 'textarea'].includes(s.tag));
    check(`${id}: every stop has a name`, unnamed.length === 0, unnamed.map(label).slice(0, 4).join(' '));
    const noRing = real.filter((s) => !s.ring && s.tag !== 'iframe');
    check(`${id}: every stop shows a focus indicator`, noRing.length === 0, noRing.map(label).slice(0, 4).join(' '));
    const hidden = real.filter((s) => !s.visible && s.w > 0);
    check(`${id}: no focused control is hidden under the bars (2.4.11)`, hidden.length === 0, hidden.map(label).slice(0, 4).join(' '));
    const zero = real.filter((s) => s.w === 0 || s.h === 0);
    check(`${id}: no invisible Tab stops`, zero.length === 0, zero.map(label).slice(0, 4).join(' '));
}

const PAGES = {
    guest: ['/', '/login', '/fares', '/forgot-password'],
    passenger: ['/ride?new=1', '/ride/trips', '/ride/safety', '/ride/account', '/ride/help/new'],
    driver: ['/drive', '/drive/earnings', '/drive/documents', '/drive/account'],
    ops: ['/ops', '/ops/live', '/ops/rides', '/ops/verification', '/ops/safety', '/ops/support'],
    business: ['/business', '/business/book', '/business/people'],
    fleet: ['/fleet', '/fleet/vehicles', '/fleet/drivers'],
};
const WHO = { guest: null, passenger: PEOPLE.passenger, driver: PEOPLE.driver, ops: PEOPLE.ops, business: PEOPLE.passenger, fleet: PEOPLE.passenger };

const b = await browser();
try {
    if (only.includes('pages')) {
        for (const [group, paths] of Object.entries(PAGES)) {
            const p = await person(b, WHO[group], { width: vp });
            for (const path of paths) { await p.page.goto(BASE + path); await sleep(900); await walk(p.page, `${vp} ${group}:${path}`); }
            await p.ctx.close();
        }
    }
    if (only.includes('live')) {
        const drv = await person(b, PEOPLE.driver, { width: vp, theme: 'dark', geo: START });
        const pax = await person(b, PEOPLE.passenger, { width: vp, geo: PICKUP });
        const phone = driverPhone(drv.api);
        await drv.page.goto(`${BASE}/drive`); await sleep(800);
        if (await drv.page.locator('[data-dr-view=online]:not([hidden])').count() === 0) {
            await drv.page.focus('[data-dr-go-online]'); await drv.page.keyboard.press('Enter');
        }
        const online = await drv.page.waitForSelector('[data-dr-view=online]:not([hidden])', { timeout: 10000 }).then(() => true).catch(() => false);
        check('driver: Go online works from the keyboard', online);
        await phone.settle(START);

        // Booking by keyboard: the place search, the price comparison, a category by arrow keys, the request.
        await pax.page.goto(`${BASE}/ride?new=1`); await sleep(1500);
        await search(pax.page, '#pax-pickup', 'Kloof');
        await search(pax.page, '#pax-dropoff', 'Bree');
        await pax.page.focus('[data-pax-see-prices]'); await pax.page.keyboard.press('Enter');
        const priced = await pax.page.waitForSelector('.pax-option', { timeout: 15000 }).then(() => true).catch(() => false);
        check('passenger: See prices works from the keyboard', priced);
        await pax.page.locator('.pax-option__pick').first().focus();
        await pax.page.keyboard.press('ArrowDown'); await sleep(200);
        const chosen = await pax.page.evaluate(() => document.activeElement?.matches('.pax-option__pick') && document.activeElement.checked);
        check('passenger: ride categories move with the arrow keys (radio group)', chosen);
        await pax.page.locator('input[name="pax-payment"][value="cash"]').check().catch(() => {});
        await pax.page.focus('[data-pax-request]');
        await Promise.all([pax.page.waitForURL(/\/ride\/trips\/\d+/, { timeout: 20000 }), pax.page.keyboard.press('Enter')]);
        const rideId = Number(pax.page.url().match(/trips\/(\d+)/)[1]);
        check('passenger: Request works from the keyboard', rideId > 0, `ride ${rideId}`);

        // The offer: modal, focus on Accept, nothing behind it reachable, countdown spoken at the marks.
        await drv.page.waitForSelector('[data-dr-offer]:not([hidden])', { timeout: 20000 });
        await sleep(300);
        const onAccept = await drv.page.evaluate(() => document.activeElement?.matches('[data-dr-accept]'));
        check('driver offer: focus lands on Accept', onAccept);
        const inside = [];
        for (let i = 0; i < 8; i++) { await drv.page.keyboard.press('Tab'); inside.push(await drv.page.evaluate(() => !!document.activeElement?.closest('[data-dr-offer]') || document.activeElement === document.body)); }
        check('driver offer: Tab never leaves the offer (background inert)', inside.every(Boolean), inside.join(','));
        await drv.page.focus('[data-dr-decline]');
        await drv.page.keyboard.press('Enter'); await sleep(200);
        const reasons = await drv.page.evaluate(() => !document.querySelector('[data-dr-decline-form]').hidden);
        check('driver offer: Decline and its reasons are reachable by keyboard', reasons);
        await drv.page.focus('[data-dr-decline-back]'); await drv.page.keyboard.press('Enter');
        const spoken = await drv.page.waitForFunction(() => [...document.querySelectorAll('[data-eh-announcer]')].some((r) => /seconds left to answer/.test(r.textContent)), null, { timeout: 15000 }).then(() => true).catch(() => false);
        check('driver offer: the countdown is announced at its marks', spoken, await drv.page.evaluate(() => [...document.querySelectorAll('[data-eh-announcer]')].map((r) => r.textContent).join(' | ')));
        await drv.page.focus('[data-dr-accept]').catch(() => {}); await drv.page.keyboard.press('Enter').catch(() => {});
        const accepted = await drv.page.waitForSelector('[data-dr-view=trip]:not([hidden])', { timeout: 10000 }).then(() => true).catch(() => false);
        check('driver offer: Accept works from the keyboard', accepted);
        const back = await drv.page.evaluate(() => ({ el: document.activeElement?.id || document.activeElement?.tagName, inert: document.querySelectorAll('[inert]').length }));
        check('driver offer: after it closes, focus is on the screen title and nothing stays inert', back.el === 'drive-state-title' && back.inert === 0, JSON.stringify(back));

        if (accepted) {
            // SOS on the driver app: open by keyboard, counted aloud, Escape stops it.
            await drv.page.focus('[data-dr-sos-open]'); await drv.page.keyboard.press('Enter'); await sleep(300);
            const sos = await drv.page.evaluate(() => ({ open: !document.querySelector('[data-dr-sos]').hidden, focus: document.activeElement?.matches('[data-dr-sos-cancel]'), said: [...document.querySelectorAll('[data-eh-announcer]')].map((r) => r.textContent).join(' ') }));
            check('driver SOS: opens by keyboard with focus on Cancel and the countdown announced', sos.open && sos.focus && /Sending SOS in 3 seconds/.test(sos.said), JSON.stringify(sos));
            await drv.page.keyboard.press('Escape'); await sleep(200);
            const stopped = await drv.page.evaluate(() => ({ hidden: document.querySelector('[data-dr-sos]').hidden, focus: document.activeElement?.matches('[data-dr-sos-open]'), inert: document.querySelectorAll('[inert]').length }));
            check('driver SOS: Escape stops it, focus returns to SOS, nothing inert', stopped.hidden && stopped.focus && stopped.inert === 0, JSON.stringify(stopped));

            // Passenger SOS by keyboard.
            await pax.page.waitForFunction(() => /on the way/i.test(document.querySelector('[data-pax-title]')?.textContent || ''), null, { timeout: 15000 });
            await pax.page.focus('[data-pax-sos-open]'); await pax.page.keyboard.press('Enter'); await sleep(300);
            await pax.page.focus('[data-sos-start]'); await pax.page.keyboard.press('Enter'); await sleep(300);
            const pcount = await pax.page.evaluate(() => ({ text: document.querySelector('[data-sos-count-text]')?.textContent, live: document.querySelector('[data-sos-count-text]')?.getAttribute('aria-live'), focus: document.activeElement?.matches('[data-sos-cancel]') }));
            check('passenger SOS: countdown is announced and focus is on Stop', /Sending SOS in/.test(pcount.text) && pcount.live === 'assertive' && pcount.focus, JSON.stringify(pcount));
            await pax.page.keyboard.press('Enter'); await sleep(300);
            const pstop = await pax.page.evaluate(() => !document.querySelector('[data-sos-step=count]') || document.querySelector('[data-sos-step=count]').hidden);
            check('passenger SOS: Enter on Stop cancels it', pstop);
            await pax.page.keyboard.press('Escape');

            // The arrival time and the map in words.
            const summary = await pax.page.evaluate(() => document.getElementById('pax-map-summary')?.textContent || '');
            check('passenger: the map has a text summary (stops, driver distance)', /Pickup: .*Drop-off: .*(driver is .* from the pickup|position is not available)/.test(summary), summary);

            // PIN keypad by keyboard digits.
            await drv.ctx.setGeolocation({ latitude: PICKUP[0], longitude: PICKUP[1], accuracy: 8 });
            await phone.drive(START, PICKUP, 6, 3);
            await drv.page.focus('[data-dr-arrived]'); await drv.page.keyboard.press('Enter'); await sleep(800);
            if (await drv.page.locator('#dr-far-dialog[open]').count()) { await drv.page.focus('[data-dr-far-confirm]'); await drv.page.keyboard.press('Enter'); }
            await drv.page.waitForSelector('[data-dr-pin]:not([hidden])', { timeout: 10000 });
            const pin = await pax.page.waitForFunction(() => document.querySelector('[data-pax-pin]')?.dataset.pin, null, { timeout: 15000 }).then((h) => h.jsonValue());
            await drv.page.evaluate(() => document.activeElement?.blur());
            await drv.page.keyboard.type(String(pin)); await sleep(300);
            const typed = await drv.page.evaluate(() => ({ said: document.querySelector('[data-dr-pin-said]')?.textContent, start: !document.querySelector('[data-dr-start]').disabled }));
            check('driver PIN: keyboard digits fill the pad, progress is announced, Start enables', typed.start && /4 of 4/.test(typed.said || ''), JSON.stringify(typed));
            await drv.page.focus('[data-dr-start]'); await drv.page.keyboard.press('Enter');
            const started = await pax.page.waitForFunction(() => /on the way to/i.test(document.querySelector('[data-pax-title]')?.textContent || ''), null, { timeout: 15000 }).then(() => true).catch(() => false);
            check('driver PIN: Start trip works from the keyboard', started);
            // End the ride for the next run.
            await drv.api('POST', `/api/ehail/driver/rides/${rideId}/complete`);
        }
        await drv.api('POST', '/api/ehail/driver/offline');
        for (const p of [drv, pax]) if (p.errors.length) log('page errors', p.errors.slice(0, 5));
    }
} finally { await b.close(); }

writeJson(`keyboard-${vp}.json`, results);
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length} checks, ${failed.length} failed.`);
process.exit(failed.length ? 1 : 0);
