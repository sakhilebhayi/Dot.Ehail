// R-46 a11y: sunlight readability of the driver app. A phone on a windscreen in daylight needs more than the AA
// 4.5:1 floor, so the driver pages in the light theme are held to WCAG AAA contrast (7:1 body, 4.5:1 large text)
// with axe-core's color-contrast-enhanced rule, at 390 px: every driver page, then a live offer and the trip to the
// pickup (a real request from a passenger in a second context; the ride is cancelled by the driver afterwards).
//   node scripts/dev/a11y/sunlight.mjs
import { clearRides, BASE, PEOPLE, PICKUP, START, axe, browser, driverPhone, person, search, setTheme, sleep, writeJson } from './lib.mjs';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const AXE = readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8');
const PAGES = ['/drive', '/drive/earnings', '/drive/earnings/payouts', '/drive/trips', '/drive/documents', '/drive/safety', '/drive/account', '/drive/onboarding', '/drive/vehicles'];
const b = await browser();
const rows = [];
const enhanced = async (page) => { await page.evaluate(AXE); return page.evaluate(async () => (await window.axe.run(document, { runOnly: ['color-contrast-enhanced'] })).violations.flatMap((x) => x.nodes.map((n) => ({ target: n.target.join(' '), why: (n.any[0] || {}).message?.slice(0, 120) })))); };
const report = (path, v) => {
    rows.push({ path, nodes: v.length, sample: v.slice(0, 6) });
    console.log(`${v.length ? 'FAIL' : 'PASS'}  ${path}  ${v.length} below AAA${v.length ? '  ' + v.slice(0, 4).map((x) => x.target + ' ' + (x.why || '').replace(/Element has insufficient color contrast of /, '')).join(' | ') : ''}`);
};
try {
    const p = await person(b, PEOPLE.driver, { width: 390, theme: 'light' });
    for (const path of PAGES) {
        await p.page.goto(BASE + path); await sleep(900); await setTheme(p.page, 'light'); await sleep(200);
        const v = await enhanced(p.page);
        report(path, v);
    }
    // The live offer and the trip in daylight.
    await clearRides(b);
    const phone = driverPhone(p.api);
    await p.page.goto(`${BASE}/drive`); await sleep(800);
    if (await p.page.locator('[data-dr-go-online]').isVisible().catch(() => false)) await p.page.click('[data-dr-go-online]');
    await phone.settle(START);
    await p.page.waitForSelector('[data-dr-view=online]:not([hidden])', { timeout: 10000 }).catch(() => {});
    await sleep(600);
    report('live: online, waiting for offers', await enhanced(p.page));
    const pax = await person(b, PEOPLE.passenger, { width: 390, geo: PICKUP });
    await pax.page.goto(`${BASE}/ride?new=1`); await sleep(1200);
    await search(pax.page, '#pax-pickup', 'Kloof'); await search(pax.page, '#pax-dropoff', 'Bree');
    await pax.page.click('[data-pax-see-prices]'); await pax.page.waitForSelector('.pax-option', { timeout: 15000 });
    await pax.page.locator('input[name="pax-payment"][value="cash"]').check().catch(() => {});
    await Promise.all([pax.page.waitForURL(/\/ride\/trips\/\d+/, { timeout: 20000 }), pax.page.click('[data-pax-request]')]);
    const rideId = Number(pax.page.url().match(/trips\/(\d+)/)[1]);
    await p.page.waitForSelector('[data-dr-offer]:not([hidden])', { timeout: 20000 });
    await sleep(600);
    report('live: offer card', await enhanced(p.page));
    const aa = await axe(p.page);
    console.log(`${aa.length ? 'FAIL' : 'PASS'}  live: offer card, light, AA sweep  ${aa.map((x) => x.id).join(' ')}`);
    await p.page.click('[data-dr-accept]');
    await p.page.waitForSelector('[data-dr-view=trip]:not([hidden])', { timeout: 10000 });
    await sleep(800);
    report('live: trip to the pickup', await enhanced(p.page));
    await p.api('POST', `/api/ehail/driver/rides/${rideId}/cancel`, { reason: 'other' });
    await clearRides(b);
    await p.api('POST', '/api/ehail/driver/offline');
    await pax.ctx.close();
} finally { await b.close(); }
writeJson('sunlight.json', rows);
process.exit(rows.some((r) => r.nodes) ? 1 : 0);
