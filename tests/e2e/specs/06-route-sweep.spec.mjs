// Every GET route as every role (guest, passenger, driver, fleet owner, business admin, each operator role) at 390 and
// 1440: no 500, exactly one h1, no horizontal overflow, no console errors, no host but the app and the configured
// tile server, no CSP violation. Route parameters are filled from the data the journeys before this file created
// (the passenger and driver of a completed, paid trip sign in as themselves); a parameter nothing can fill is skipped
// and counted. Writes out/route-sweep-<role>.json.
import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { browser, env, makePerson, one, world, externalHosts, closeBrowser, PASSWORD } from '../lib/harness.mjs';
import { PASS } from '../lib/flows.mjs';

const SKIP = [/^livewire\//, /^storage\//, /^sanctum\//, /^up$/, /^up\/realtime$/, /^two-factor-challenge$/, /^user\/two-factor/, /^user\/confirmed-password-status$/,
    /^team-invitations\//, /^reset-password\//, /^auth\/ecosystem$/, /^register$/, /^forgot-password$/, /^login$/, /^user\/confirm-password$/, /^_/, /\{token\}/, /\{path\}/, /\{filename\}/,
    /\{contact\}/, /\{message\}/, /\{invitation\}/, /^logout$/,
    /^broadcasting\/auth$/]; // an endpoint for the WebSocket client, not a page

function routes() {
    const out = execFileSync('php', ['artisan', 'route:list', '--json'], { cwd: env.root, env: process.env, encoding: 'utf8' });
    return JSON.parse(out).filter((r) => r.method.split('|').includes('GET')).map((r) => r.uri.replace(/^\/?/, '')).filter((u) => !SKIP.some((re) => re.test(u)));
}

const roles = {};
const params = {};

test.before(async () => {
    world('offline-all');
    const paid = one("select r.id ride, r.passenger_id, r.driver_id from rides r join receipts c on c.ride_id = r.id where r.status = 'completed' order by r.id desc limit 1");
    assert.ok(paid, 'run the journeys first: the sweep signs in as their passenger and driver');
    const user = (id) => one('select id, email from users where id = ?', [id]);
    roles.guest = null;
    roles.passenger = user(paid.passenger_id);
    roles.driver = user(paid.driver_id);
    for (const role of ['admin', 'ops_agent', 'dispatcher', 'verification_reviewer', 'safety_officer', 'support_agent', 'finance', 'pricing_manager']) roles[role] = makePerson('operator', role, { roles: [role] });
    roles.fleet_owner = makePerson('fleet-owner', 'FleetOwner');
    roles.business_admin = makePerson('business-admin', 'BusinessAdmin');

    const id = (q, p = []) => { try { return one(q, p)?.id ?? null; } catch { return null; } };
    Object.assign(params, {
        ride: paid.ride,
        receipt: id('select id from receipts where ride_id = ?', [paid.ride]),
        document: id('select id from verification_documents where user_id = ? order by id', [paid.driver_id]) ?? id('select id from verification_documents order by id desc'),
        incident: id('select id from safety_incidents order by id desc'),
        batch: id('select id from payout_batches order by id desc'),
        payout: id('select id from payouts order by id desc'),
        payment: id("select id from ride_payments where ride_id = ?", [paid.ride]),
        earning: id('select id from driver_earnings where ride_id = ?', [paid.ride]),
        offer: id('select id from ride_offers where ride_id = ?', [paid.ride]),
        fleet: id('select id from fleets order by id'),
        profile: id('select id from driver_profiles where user_id = ?', [paid.driver_id]),
        driverProfile: id('select id from driver_profiles where user_id = ?', [paid.driver_id]),
        driver: paid.driver_id,
        vehicle: id('select id from vehicles order by id desc'),
        case: id('select id from support_cases order by id desc'),
        quote: id('select id from fare_quotes where ride_id = ?', [paid.ride]),
        ruleSet: id("select id from pricing_rule_sets where status = 'published' order by id"),
        account: id('select id from business_accounts order by id desc'),
        statement: id('select id from business_statements order by id desc'),
        team: id('select id from teams order by id'),
    });
});

const fill = (uri) => {
    let miss = false;
    const url = '/' + uri.replace(/\{(\w+)\??\}/g, (_, k) => { if (params[k] == null) { miss = true; return 'x'; } return String(params[k]); });
    return miss ? null : url;
};

async function contextFor(role, width) {
    const b = await browser();
    const ctx = await b.newContext({ baseURL: env.base, ignoreHTTPSErrors: true, viewport: { width, height: width === 390 ? 844 : 900 }, acceptDownloads: true });
    await ctx.addInitScript(() => { window.__csp = []; document.addEventListener('securitypolicyviolation', (e) => window.__csp.push(`${e.violatedDirective} ${e.blockedURI}`)); });
    const user = roles[role];
    if (user) {
        for (const password of [PASSWORD, PASS]) {
            const page = await ctx.newPage();
            await page.goto('/login');
            await page.fill('input[name=email]', user.email);
            await page.fill('input[name=password]', password);
            await page.click('button[type=submit]');
            await page.waitForLoadState('load');
            const ok = !new URL(page.url()).pathname.startsWith('/login');
            await page.close();
            if (ok) return ctx;
        }
        throw new Error(`could not sign in as ${role}`);
    }
    return ctx;
}

async function sweep(role, width, urls) {
    const ctx = await contextFor(role, width);
    const page = await ctx.newPage();
    const errors = []; const requests = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
    page.on('request', (r) => requests.push(r.url()));
    const report = []; const failures = [];
    for (const url of urls) {
        errors.length = 0;
        const start = requests.length;
        let resp;
        try { resp = await page.goto(url, { waitUntil: 'load', timeout: 30000 }); } catch (e) {
            if (/Download is starting/.test(e.message)) {
                const r = await page.request.get(url);
                if (r.status() >= 500) failures.push(`${role}@${width} ${url}: HTTP ${r.status()}`);
                report.push({ url, status: r.status(), type: 'download' });
                continue;
            }
            failures.push(`${role}@${width} ${url}: navigation failed ${e.message.slice(0, 120)}`);
            continue;
        }
        const status = resp.status();
        const type = (resp.headers()['content-type'] || '').split(';')[0];
        const row = { url, final: new URL(page.url()).pathname, status, type };
        if (status >= 500) failures.push(`${role}@${width} ${url}: HTTP ${status}`);
        if (type === 'text/html') {
            await page.waitForLoadState('networkidle', { timeout: 2500 }).catch(() => {});
            const m = await page.evaluate(() => {
                const overflow = document.documentElement.scrollWidth - window.innerWidth;
                // For the report: the outermost elements that stick out past the viewport (what to fix).
                const culprits = overflow > 1 ? [...document.querySelectorAll('body *')].filter((e) => e.getBoundingClientRect().right > window.innerWidth + 1 && !(e.parentElement && e.parentElement.getBoundingClientRect().right > window.innerWidth + 1))
                    .slice(0, 3).map((e) => `${e.tagName.toLowerCase()}${e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/).join('.') : ''}`) : [];
                return { h1: document.querySelectorAll('h1').length, overflow, culprits, csp: window.__csp || [] };
            });
            Object.assign(row, { h1: m.h1, overflow: m.overflow });
            if (m.h1 !== 1) failures.push(`${role}@${width} ${url} -> ${row.final}: ${m.h1} h1 (HTTP ${status})`);
            if (m.overflow > 1) failures.push(`${role}@${width} ${url} -> ${row.final}: horizontal overflow ${m.overflow}px (${m.culprits.join(', ')})`);
            if (m.csp.length) failures.push(`${role}@${width} ${url}: CSP ${[...new Set(m.csp)].join(', ').slice(0, 200)}`);
            // Chrome's own viewer for an image or PDF served earlier with a CSP sandbox (private document files)
            // reports "Blocked script execution in '<that file>'" late, into the next navigation: not this page's.
            const here = page.url();
            const errs = errors.filter((e) => !/status of (401|403|404|409|419|422|429)/.test(e))
                .filter((e) => { const m2 = e.match(/^Blocked script execution in '([^']+)' because the document's frame is sandboxed/); return !m2 || m2[1] === here; });
            if (errs.length) failures.push(`${role}@${width} ${url}: console ${JSON.stringify(errs).slice(0, 300)}`);
            const ext = externalHosts(requests.slice(start));
            if (ext.length) failures.push(`${role}@${width} ${url}: external hosts ${ext.join(', ')}`);
        }
        report.push(row);
    }
    await ctx.close();
    return { report, failures };
}

test('every GET route as every role at 390 and 1440', async (t) => {
    const all = routes();
    const urls = all.map(fill).filter(Boolean);
    t.diagnostic(`${all.length} GET routes, ${urls.length} swept, ${all.length - urls.length} skipped for want of a parameter value`);
    const jobs = [];
    for (const role of Object.keys(roles)) for (const width of [1440, 390]) jobs.push([role, width]);
    const failures = [];
    const pool = 4;
    let next = 0;
    await Promise.all(Array.from({ length: pool }, async () => {
        while (next < jobs.length) {
            const [role, width] = jobs[next++];
            const res = await sweep(role, width, urls);
            writeFileSync(join(env.out, `route-sweep-${role}-${width}.json`), JSON.stringify(res.report, null, 1));
            t.diagnostic(`${role}@${width}: ${res.report.length} loads, ${res.failures.length} problems`);
            failures.push(...res.failures);
        }
    }));
    writeFileSync(join(env.out, 'route-sweep-failures.json'), JSON.stringify(failures, null, 1));
    assert.deepEqual(failures, [], `${failures.length} problems (out/route-sweep-failures.json)`);
});

test.after(async () => { await closeBrowser(); });
