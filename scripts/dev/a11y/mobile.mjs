// R-46 a11y: mobile usability in real Chrome with touch emulation. For every page at 320, 360, 390 and 430 px and in
// landscape (844x390): no horizontal scroll; touch targets (44 px, driver primary actions 56 px); where the primary
// action sits (thumb reach); text at 200% (root font size doubled) without horizontal scroll; the virtual keyboard
// (viewport shortened to 55%) never covering the focused field; heavy effects (backdrop blur, blur filters) on
// surfaces that scroll; the offline notice on the booking and live-ride pages.
//   node scripts/dev/a11y/mobile.mjs [--filter regex]
import { BASE, PEOPLE, arg, browser, log, person, sleep, writeJson } from './lib.mjs';

const filter = arg('filter') ? new RegExp(arg('filter')) : null;
const WIDTHS = [[320, 640], [360, 780], [390, 844], [430, 932], [844, 390]];
const results = [];
const check = (name, ok, info = '') => { results.push({ name, ok: !!ok, info }); if (!ok || process.argv.includes('--verbose')) console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  [' + String(info).slice(0, 260) + ']' : ''}`); };

const PAGES = {
    guest: ['/', '/login', '/fares', '/forgot-password', '/terms-of-service'],
    passenger: ['/ride?new=1', '/ride/trips', '/ride/safety', '/ride/account', '/ride/help', '/ride/help/new', '/ride/notifications', '/user/profile'],
    driver: ['/drive', '/drive/earnings', '/drive/earnings/payouts', '/drive/documents', '/drive/trips', '/drive/safety', '/drive/account', '/drive/vehicles'],
    ops: ['/ops', '/ops/live', '/ops/rides', '/ops/drivers', '/ops/verification', '/ops/safety', '/ops/support', '/ops/tariffs', '/ops/finance', '/ops/insights'],
    business: ['/business', '/business/trips', '/business/book', '/business/people', '/business/policies', '/business/invoices', '/business/approvals', '/business/settings'],
    fleet: ['/fleet', '/fleet/vehicles', '/fleet/drivers', '/fleet/earnings', '/fleet/documents', '/fleet/maintenance', '/fleet/alerts', '/fleet/trips', '/fleet/settings'],
    newcomer: ['/drive', '/drive/apply'],
};
const WHO = { guest: null, passenger: PEOPLE.passenger, driver: PEOPLE.driver, ops: PEOPLE.ops, business: PEOPLE.passenger, fleet: PEOPLE.passenger, newcomer: PEOPLE.newcomer };
const FIELDS = { '/ride?new=1': '#pax-pickup', '/ride/help/new': 'textarea', '/login': 'input[name=password]', '/drive/apply': 'input[type=text], input:not([type])', '/business/book': 'input[name=pickup_query]' };

const MEASURE = `(() => {
    const visible = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && !e.closest('[hidden],[inert],dialog:not([open]),details:not([open]) > :not(summary)'); };
    // Inline in a sentence (WCAG 2.5.8 exception): a link inside running text that holds more than the link.
    const inline = (e) => { if (e.tagName !== 'A') return false; const block = e.closest('p, td, dd, .eh-prose, label'); return !!block && block.textContent.trim().length > e.textContent.trim().length + 3; };
    const all = [...document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button], [role=tab], [role=switch]')].filter(visible).filter((e) => !e.closest('.leaflet-control-attribution'));
    const driverPrimary = [...document.querySelectorAll('.dr-primary, .dr-golive, .dr-accept, .dr-decline, .eh-btn--driver')].filter(visible);
    const tag = (e) => { const r = e.getBoundingClientRect(); return e.tagName.toLowerCase() + (typeof e.className === 'string' && e.className ? '.' + e.className.split(' ')[0] : '') + '[' + (e.getAttribute('aria-label') || e.textContent || e.name || '').trim().replace(/\\s+/g, ' ').slice(0, 22) + '] ' + Math.round(r.width) + 'x' + Math.round(r.height); };
    // The real target: what a tap at a point hits (pseudo-elements that extend a target and an input's own label
    // count), measured by hit-testing outward from the centre, not the drawn box.
    const owns = (e, hit) => hit && (hit === e || e.contains(hit) || (e.labels && [...e.labels].some((l) => l === hit || l.contains(hit))));
    const reach = (e) => {
        const box = (e.closest('label') && e.matches('input') ? e.closest('label') : e);
        box.scrollIntoView({ block: 'center', inline: 'center' });
        const r = box.getBoundingClientRect(); const cx = r.left + r.width / 2; const cy = r.top + r.height / 2;
        const run = (dx, dy) => { let n = 0; while (n < 40 && owns(e, document.elementFromPoint(cx + dx * (n + 1), cy + dy * (n + 1)))) n++; return n; };
        return { h: run(0, -1) + run(0, 1) + 1, w: run(-1, 0) + run(1, 0) + 1 };
    };
    const tooSmall = (e) => { const r = e.getBoundingClientRect(); if (Math.min(r.width, r.height) >= 44 - 0.5) return false; const t = reach(e); return Math.min(t.w, t.h) < 44 - 0.5; };
    const small = all.filter((e) => !inline(e) && !e.matches('input[type=radio], input[type=checkbox]') && !(e.closest('label') && e.closest('label').getBoundingClientRect().height >= 44)).filter(tooSmall);
    const radios = all.filter((e) => e.matches('input[type=radio], input[type=checkbox]')).filter((e) => { const box = (e.closest('label') || e).getBoundingClientRect(); return box.height < 44 - 0.5 && tooSmall(e); });
    window.scrollTo(0, 0);
    const dsmall = driverPrimary.filter((e) => e.getBoundingClientRect().height < 56 - 0.5);
    const heavy = [...document.querySelectorAll('body *')].filter((e) => { const s = getComputedStyle(e); return (s.backdropFilter && s.backdropFilter !== 'none') || /blur\\(/.test(s.filter); }).map(tag);
    return { overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1, scrollW: document.documentElement.scrollWidth, small: small.map(tag), radios: radios.map(tag), dsmall: dsmall.map(tag), heavy };
})()`;

const b = await browser();
try {
    for (const [group, paths] of Object.entries(PAGES)) {
        if (filter && !paths.some((p) => filter.test(`${group}:${p}`))) continue;
        const p = await person(b, WHO[group], { width: 390, theme: group === 'driver' ? 'dark' : 'light' });
        for (const path of paths) {
            const id = `${group}:${path}`;
            if (filter && !filter.test(id)) continue;
            await p.page.goto(BASE + path); await sleep(800);
            for (const [w, h] of WIDTHS) {
                await p.page.setViewportSize({ width: w, height: h }); await sleep(300);
                const m = await p.page.evaluate(MEASURE);
                const tagw = w > h ? `${w}x${h} landscape` : `${w}`;
                check(`${id} @${tagw}: no horizontal scroll`, !m.overflowX, m.overflowX ? `scrollWidth ${m.scrollW}` : '');
                if (w === 390) {
                    check(`${id} @390: touch targets 44 px`, m.small.length === 0, `${m.small.length}: ${m.small.slice(0, 6).join(' | ')}`);
                    check(`${id} @390: radio and checkbox rows 44 px`, m.radios.length === 0, m.radios.slice(0, 4).join(' | '));
                    if (group === 'driver' || group === 'newcomer') check(`${id} @390: driver primary actions 56 px`, m.dsmall.length === 0, m.dsmall.join(' | '));
                    check(`${id} @390: no backdrop blur or blur filters`, m.heavy.length === 0, m.heavy.slice(0, 4).join(' | '));
                }
            }
            // Text at 200% (WCAG 1.4.4): the root font size doubled, at 390 px.
            await p.page.setViewportSize({ width: 390, height: 844 });
            await p.page.evaluate(() => { document.documentElement.style.fontSize = '200%'; }); await sleep(250);
            const zoomed = await p.page.evaluate(MEASURE);
            check(`${id} @390 text 200%: no horizontal scroll`, !zoomed.overflowX, zoomed.overflowX ? `scrollWidth ${zoomed.scrollW}` : '');
            await p.page.evaluate(() => { document.documentElement.style.fontSize = ''; });
            // The virtual keyboard: the visual viewport shrinks to about 55%; the focused field must stay visible.
            if (FIELDS[path]) {
                await p.page.setViewportSize({ width: 390, height: 464 });
                const field = p.page.locator(FIELDS[path]).first();
                if (await field.count()) {
                    await field.focus(); await sleep(400);
                    const seen = await p.page.evaluate(() => { const a = document.activeElement; const r = a.getBoundingClientRect(); const hit = document.elementFromPoint(r.left + Math.min(20, r.width / 2), r.top + Math.min(12, r.height / 2)); return { top: Math.round(r.top), bottom: Math.round(r.bottom), h: innerHeight, covered: !(hit && (hit === a || a.contains(hit) || hit.contains(a))) }; });
                    check(`${id}: focused field stays visible above the keyboard`, seen.top >= 0 && seen.bottom <= seen.h && !seen.covered, JSON.stringify(seen));
                }
                await p.page.setViewportSize({ width: 390, height: 844 });
            }
        }
        if (p.errors.length) log(group, 'page errors', p.errors.slice(0, 4));
        await p.ctx.close();
    }
    // Offline notice on booking (the trip page has the same banner; the sweep sees it in the journey).
    if (!filter || filter.test('offline')) {
        const p = await person(b, PEOPLE.passenger, { width: 390 });
        await p.page.goto(`${BASE}/ride?new=1`); await sleep(1000);
        await p.ctx.setOffline(true); await sleep(600);
        const offline = await p.page.evaluate(() => [...document.querySelectorAll('[role=status], [role=alert], .eh-banner, [aria-live]')].map((e) => e.textContent.trim()).filter((t) => /offline|connection/i.test(t)).join(' | '));
        check('passenger booking: going offline shows a readable notice', offline.length > 0, offline);
        await p.ctx.setOffline(false);
        await p.ctx.close();
    }
} finally { await b.close(); }

writeJson('mobile.json', results);
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length} checks, ${failed.length} failed.`);
process.exit(failed.length ? 1 : 0);
