// R-46 accessibility sweep: axe-core (WCAG 2.0/2.1/2.2 A and AA plus best practice) in real Chrome over every page
// and the live states of a real ride (passenger and driver in separate browser contexts on the real dispatch,
// pricing, geo, safety and money backends; only routing and geocoding are local stand-ins), at 390, 768 and 1440 px
// in the light and night themes. Dev database only.
//
//   sh scripts/dev/a11y/serve.sh &              # dev server on 8623 with the geo stand-in and cash on
//   php scripts/dev/a11y/fixtures.php           # people and reference data
//   node scripts/dev/a11y/sweep.mjs             # journey states + every page, all 6 combinations
//   node scripts/dev/a11y/sweep.mjs --only pages --filter '^ops' --vp 390 --theme dark
//   node scripts/dev/a11y/sweep.mjs --ci        # exit 1 on any serious or critical violation
//
// Report: storage/a11y/sweep.json (git-ignored) and a summary on stdout.
import { search, BASE, PEOPLE, PICKUP, DEST, START, IMPACT, arg, has, axe, browser, driverPhone, facts, log, person, php, setTheme, sleep, writeJson, HEIGHT } from './lib.mjs';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT } from './lib.mjs';

const only = arg('only', 'journey,pages').split(',');
const filter = arg('filter') ? new RegExp(arg('filter')) : null;
const VPS = (arg('vp') || '390,768,1440').split(',').map(Number);
const THEMES = (arg('theme') || 'light,dark').split(',');
const rows = [];

function print(row) {
    const worst = row.violations.reduce((m, v) => Math.max(m, IMPACT[v.impact] || 0), 0);
    const tag = row.error ? 'ERROR' : worst >= 3 ? 'FAIL ' : row.violations.length ? 'minor' : 'ok   ';
    const extra = `${row.overflowX ? ' [H-SCROLL]' : ''}${row.dupIds?.length ? ' [dup ids ' + row.dupIds.slice(0, 3).join(',') + ']' : ''}${row.h1 !== undefined && row.h1 !== 1 ? ` [h1=${row.h1}]` : ''}`;
    console.log(`${tag} ${row.theme.padEnd(5)} ${String(row.vp).padStart(4)} ${row.id.padEnd(30)} ${row.error || row.violations.map((v) => `${v.id}(${v.impact[0]}:${v.nodes})`).join(' ')}${extra}`);
}

/** Judge the page as it stands at every width and theme asked for (no reload: live states stay live). */
async function capture(id, page, { vps = VPS, themes = THEMES } = {}) {
    if (filter && !filter.test(id)) return;
    for (const theme of themes) {
        await setTheme(page, theme);
        for (const vp of vps) {
            const row = { id, vp, theme, violations: [] };
            try {
                await page.setViewportSize({ width: vp, height: HEIGHT[vp] || 900 });
                await sleep(250);
                row.violations = await axe(page);
                Object.assign(row, await facts(page));
            } catch (e) { row.error = String(e.message || e).split('\n')[0].slice(0, 180); }
            rows.push(row); print(row);
        }
    }
    await page.setViewportSize({ width: VPS[0], height: HEIGHT[VPS[0]] || 900 });
}

async function journey(b) {
    const drv = await person(b, PEOPLE.driver, { geo: START });
    const pax = await person(b, PEOPLE.passenger, { geo: PICKUP });
    const phone = driverPhone(drv.api);
    await drv.api('PUT', '/money/driver/settings', { accepts_cash: true });
    await drv.api('POST', '/api/ehail/driver/offline');
    // Clear anything left from an earlier run.
    const left = JSON.parse(php('scripts/dev/a11y/ids.php')).active || [];
    if (left.length) {
        const ops = await person(b, PEOPLE.ops);
        for (const id of left) log('force-cancel left-over ride', id, (await ops.api('POST', `/api/ehail/ops/rides/${id}/force-cancel`, { reason: 'Accessibility sweep: clearing a dev ride left by an earlier run.' })).status);
        await ops.ctx.close();
    }

    await drv.page.goto(`${BASE}/drive`); await sleep(800);
    await capture('drv-offline', drv.page);
    await drv.page.click('[data-dr-go-online]');
    await drv.page.waitForSelector('[data-dr-view=online]:not([hidden])', { timeout: 10000 });
    await phone.settle(START);
    await sleep(1500);
    await capture('drv-online', drv.page);

    await pax.page.goto(`${BASE}/ride?new=1`); await sleep(1500);
    await capture('pax-booking-empty', pax.page);
    await search(pax.page, '#pax-pickup', 'Kloof');
    await search(pax.page, '#pax-dropoff', 'Bree');
    await sleep(800);
    await capture('pax-booking-route', pax.page);
    await pax.page.click('[data-pax-see-prices]');
    await pax.page.waitForSelector('.pax-option', { timeout: 15000 });
    await sleep(800);
    await capture('pax-compare', pax.page);
    await pax.page.locator('.pax-option').first().locator('.pax-option__pick').check();
    await pax.page.locator('.pax-option').first().locator('.pax-option__more').click();
    await sleep(400);
    await capture('pax-compare-expanded', pax.page);
    await pax.page.locator('input[name="pax-payment"][value="cash"]').check().catch(() => log('no cash option'));
    await Promise.all([pax.page.waitForURL(/\/ride\/trips\/\d+/, { timeout: 20000 }), pax.page.click('[data-pax-request]')]);
    const rideId = Number(pax.page.url().match(/trips\/(\d+)/)[1]);
    log('ride', rideId);
    await sleep(1200);
    await capture('pax-searching', pax.page);

    await drv.page.waitForSelector('[data-dr-offer]:not([hidden])', { timeout: 20000 });
    // An offer lives about 15 s: judge it once here (night), its decline reasons, then accept. The second ride's offer
    // is judged in the light theme and at 1440 below.
    await capture('drv-offer', drv.page, { vps: [390], themes: ['dark'] });
    await drv.page.click('[data-dr-decline]');
    await capture('drv-offer-decline-reasons', drv.page, { vps: [390], themes: ['dark'] });
    await drv.page.click('[data-dr-decline-back]');
    await drv.page.click('[data-dr-accept]');
    await pax.page.waitForFunction(() => /on the way/i.test(document.querySelector('[data-pax-title]')?.textContent || ''), null, { timeout: 15000 });
    await sleep(1200);
    await capture('pax-assigned', pax.page);
    await capture('drv-to-pickup', drv.page);
    const pin = await pax.page.locator('[data-pax-pin]').getAttribute('data-pin') || (await pax.page.locator('[data-pax-pin]').innerText()).replace(/\D/g, '');

    await drv.ctx.setGeolocation({ latitude: PICKUP[0], longitude: PICKUP[1], accuracy: 8 });
    await phone.drive(START, PICKUP, 6, 3);
    await drv.page.click('[data-dr-arrived]');
    await sleep(800);
    if (await drv.page.locator('#dr-far-dialog[open]').count()) await drv.page.click('[data-dr-far-confirm]');
    await pax.page.waitForFunction(() => /is here/i.test(document.querySelector('[data-pax-title]')?.textContent || ''), null, { timeout: 15000 });
    await sleep(1500);
    await capture('pax-arrived', pax.page);
    await drv.page.waitForSelector('[data-dr-pin]:not([hidden])', { timeout: 10000 });
    await capture('drv-pin-keypad', drv.page);
    for (const d of String(pin)) await drv.page.click(`[data-dr-key="${d}"]`);
    await drv.page.click('[data-dr-start]');
    await pax.page.waitForFunction(() => /on the way to/i.test(document.querySelector('[data-pax-title]')?.textContent || ''), null, { timeout: 15000 });
    await sleep(1200);
    await capture('pax-in-progress', pax.page);
    await capture('drv-in-trip', drv.page);

    await pax.page.click('[data-pax-share]');
    await pax.page.waitForSelector('[data-pax-share-box]:not([hidden])', { timeout: 10000 }).catch(() => {});
    await sleep(500);
    const shareUrl = await pax.page.locator('#pax-share-url').inputValue().catch(() => null);
    await capture('pax-share', pax.page, { vps: [390, 1440] });
    await pax.page.click('[data-pax-sos-open]');
    await pax.page.waitForSelector('#pax-sos[open]');
    await capture('pax-sos-dialog', pax.page);
    await pax.page.click('[data-sos-start]');
    await capture('pax-sos-countdown', pax.page, { vps: [390], themes: ['light'] });
    await pax.page.click('[data-sos-cancel]');
    await pax.page.keyboard.press('Escape');

    await drv.page.click('[data-dr-sos-open]');
    await capture('drv-sos-countdown', drv.page, { vps: [390], themes: ['dark'] });
    await drv.page.click('[data-dr-sos-cancel]');

    await drv.ctx.setGeolocation({ latitude: DEST[0], longitude: DEST[1], accuracy: 8 });
    await phone.drive(PICKUP, DEST, 9, 3);
    await drv.page.click('[data-dr-complete]');
    await drv.page.waitForSelector('#dr-complete-dialog[open]');
    await capture('drv-complete-dialog', drv.page, { vps: [390, 1440] });
    await drv.page.click('[data-dr-complete-confirm]');
    php('artisan', ['queue:work', '--stop-when-empty', '--quiet']);
    await drv.page.waitForSelector('[data-dr-view=summary]:not([hidden])', { timeout: 30000 });
    await sleep(1500);
    await capture('drv-summary', drv.page);
    await pax.page.waitForFunction(() => /arrived/i.test(document.querySelector('[data-pax-title]')?.textContent || ''), null, { timeout: 40000 });
    await sleep(2000);
    await capture('pax-completed', pax.page);
    await drv.api('POST', `/money/driver/rides/${rideId}/cash-received`, { amount_minor: 100000 });

    if (shareUrl) {
        const guest = await person(b, null);
        await guest.page.goto(shareUrl); await sleep(1200);
        await capture('pub-trip-share', guest.page);
        await guest.ctx.close();
    }

    // A second ride (the driver stays online: prices need a car nearby): SOS sent for real (what we did), then the
    // cancel dialog and the cancelled state. The driver's offer for it is left to expire.
    await drv.page.goto(`${BASE}/drive`);
    await pax.page.goto(`${BASE}/ride?new=1`); await sleep(1200);
    await search(pax.page, '#pax-pickup', 'Kloof'); await search(pax.page, '#pax-dropoff', 'Observatory');
    await pax.page.click('[data-pax-see-prices]');
    await pax.page.waitForSelector('.pax-option', { timeout: 15000 });
    await pax.page.locator('input[name="pax-payment"][value="cash"]').check().catch(() => {});
    await Promise.all([pax.page.waitForURL(/\/ride\/trips\/\d+/), pax.page.click('[data-pax-request]')]);
    const second = Number(pax.page.url().match(/trips\/(\d+)/)[1]);
    if (await drv.page.waitForSelector('[data-dr-offer]:not([hidden])', { timeout: 15000 }).catch(() => null)) {
        await capture('drv-offer', drv.page, { vps: [390, 1440], themes: ['light'] });
    }
    await pax.page.click('[data-pax-sos-open]'); await pax.page.click('[data-sos-start]');
    await pax.page.waitForURL(/safety\/incidents\/\d+/, { timeout: 20000 });
    await sleep(800);
    await capture('pax-what-we-did', pax.page);
    await pax.page.goto(`${BASE}/ride/trips/${second}`); await sleep(1000);
    await pax.page.click('[data-pax-cancel]'); await pax.page.waitForSelector('#pax-cancel-dialog[open]');
    await sleep(500);
    await capture('pax-cancel-dialog', pax.page);
    await pax.page.click('[data-pax-cancel-confirm]'); await sleep(2000);
    await capture('pax-cancelled', pax.page);

    await drv.api('POST', '/api/ehail/driver/offline');
    for (const p of [drv, pax]) if (p.errors.length) log('page errors', p.errors.slice(0, 6));
    await drv.ctx.close(); await pax.ctx.close();
    return { rideId, second, shareUrl };
}

function pageList(ids) {
    const i = (k) => ids[k];
    const list = {
        guest: ['/', '/login', '/forgot-password', '/register', '/fares', '/terms-of-service', '/privacy-policy', '/cookies', '/offline.html', '/does-not-exist', '/reset-password/not-a-token?email=x%40example.test'],
        passenger: ['/ride?new=1', '/ride/trips', '/ride/account', '/ride/help', '/ride/help/new', i('case') && `/ride/help/${i('case')}`, '/ride/safety', i('incident') && `/ride/safety/incidents/${i('incident')}`, '/ride/notifications', '/ride/notifications/settings', '/fares', '/user/profile', '/dashboard', '/notifications', '/notifications/settings'],
        business: ['/business', '/business/trips', '/business/book', '/business/people', '/business/policies', '/business/approvals', '/business/invoices', '/business/settings', i('statement') && `/business/statements/${i('statement')}`],
        fleet: ['/fleet', '/fleet/vehicles', '/fleet/vehicles/new', i('fleetVehicle') && `/fleet/vehicles/${i('fleetVehicle')}`, '/fleet/drivers', i('fleetDriver') && `/fleet/drivers/${i('fleetDriver')}`, '/fleet/trips', '/fleet/earnings', '/fleet/documents', '/fleet/maintenance', '/fleet/alerts', '/fleet/settings'],
        driver: ['/drive/trips', i('driverRide') && `/drive/trips/${i('driverRide')}`, '/drive/earnings', '/drive/earnings/payouts', '/drive/documents', '/drive/onboarding', '/drive/vehicles', i('vehicle') && `/drive/vehicles/${i('vehicle')}/edit`, '/drive/safety', '/drive/account', '/drive/notifications', '/drive/notifications/settings'],
        newcomer: ['/drive', '/drive/apply', '/fleet/register', '/business'],
        ops: ['/ops', '/ops/live', '/ops/rides', i('opsRide') && `/ops/rides/${i('opsRide')}`, '/ops/drivers', i('driverUser') && `/ops/drivers/${i('driverUser')}`, '/ops/verification', i('document') && `/ops/verification/documents/${i('document')}`, '/ops/verification/expiry',
            '/ops/safety', i('incident') && `/ops/safety/incidents/${i('incident')}`, '/ops/safety/check-ins', '/ops/safety/patterns', '/ops/safety/reports', '/ops/safety/access', '/ops/support', i('case') && `/ops/support/cases/${i('case')}`,
            '/ops/tariffs', '/ops/tariffs/catalogue', '/ops/tariffs/commission', '/ops/tariffs/promotions', '/ops/tariffs/simulator', i('ruleSet') && `/ops/tariffs/${i('ruleSet')}`,
            '/ops/finance', '/ops/finance/ledger', '/ops/finance/payouts', '/ops/finance/reconciliation', i('payment') && `/ops/finance/payments/${i('payment')}`,
            '/ops/fleets', '/ops/business', i('account') && `/ops/business/${i('account')}`, '/ops/insights', '/ops/areas', '/ops/audit', '/ops/team', '/ops/notifications', '/ops/notifications/settings'],
    };
    return Object.fromEntries(Object.entries(list).map(([k, v]) => [k, v.filter(Boolean)]));
}

async function pages(b, ids) {
    const who = { guest: null, passenger: PEOPLE.passenger, business: PEOPLE.passenger, fleet: PEOPLE.passenger, driver: PEOPLE.driver, newcomer: PEOPLE.newcomer, ops: PEOPLE.ops };
    const list = pageList(ids);
    for (const [group, paths] of Object.entries(list)) {
        if (filter && !paths.some((p) => filter.test(`${group}:${p}`))) continue;
        const p = await person(b, who[group]);
        for (const path of paths) {
            const id = `${group}:${path}`;
            if (filter && !filter.test(id)) continue;
            const res = await p.page.goto(BASE + path).catch((e) => ({ status: () => 'ERR ' + e.message }));
            const status = res?.status?.();
            if (status !== 200 && !(path === '/does-not-exist' && status === 404)) log('status', status, id);
            await sleep(700);
            await capture(id, p.page);
        }
        if (p.errors.length) log(group, 'page errors', p.errors.slice(0, 6));
        await p.ctx.close();
    }
    // Error views that cannot be reached on a running dev server (403, 419, 429, 500, 503) are rendered to files.
    php('scripts/dev/a11y/render-errors.php');
    const g = await person(b, null);
    for (const code of [403, 419, 429, 500, 503]) {
        await g.page.goto(`${BASE}/login`);
        await g.page.setContent(readFileSync(join(ROOT, `storage/a11y/errors/${code}.html`), 'utf8'), { waitUntil: 'load' }).catch(() => {});
        await capture(`error:${code}`, g.page);
    }
    await g.ctx.close();
}

const b = await browser();
process.on('unhandledRejection', (e) => { console.error('FAILED', String(e?.message || e).split('\n')[0]); });
try {
    if (only.includes('journey')) {
        try { await journey(b); } catch (e) { rows.push({ id: 'journey', vp: 0, theme: '-', violations: [], error: String(e.message || e).split('\n')[0] }); console.error('JOURNEY STOPPED', String(e.message || e).split('\n').slice(0, 3).join(' | ')); }
    }
    if (only.includes('pages')) await pages(b, JSON.parse(php('scripts/dev/a11y/ids.php')));
} finally { await b.close(); }

const name = arg('json', 'sweep.json');
const path = writeJson(name, rows);
const serious = rows.filter((r) => r.violations.some((v) => IMPACT[v.impact] >= 3));
const all = rows.flatMap((r) => r.violations);
const byRule = {};
for (const r of rows) for (const v of r.violations) { byRule[v.id] ??= { impact: v.impact, states: new Set(), nodes: 0 }; byRule[v.id].states.add(r.id); byRule[v.id].nodes += v.nodes; }
console.log('\nRules:');
for (const [id, x] of Object.entries(byRule).sort((a, c) => IMPACT[c[1].impact] - IMPACT[a[1].impact])) console.log(`  ${id.padEnd(28)} ${x.impact.padEnd(9)} ${String(x.nodes).padStart(5)} nodes in ${x.states.size} states: ${[...x.states].slice(0, 6).join(', ')}`);
console.log(`\n${rows.length} page states, ${rows.filter((r) => r.error).length} errors, ${serious.length} with serious/critical, ${all.length} violations (${all.filter((v) => IMPACT[v.impact] >= 3).length} serious/critical), ${rows.filter((r) => r.overflowX).length} with horizontal scroll. Report: ${path}`);
if (has('ci') && (serious.length || rows.some((r) => r.error))) process.exit(1);
