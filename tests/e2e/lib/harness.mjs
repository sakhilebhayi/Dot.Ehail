// Shared helpers for the specs: environment, direct database reads, fixtures (support/world.php), artisan (the host's
// cron), Chrome contexts for each person, request/console watching, stub control, and the driver's emulated phone.
import { execFileSync } from 'node:child_process';
import { mkdirSync, existsSync, readFileSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DatabaseSync } from 'node:sqlite';
import { chromium } from 'playwright-core';
import { roadPath, pathMetres, pointAlong } from './geo.mjs';

export const env = {
    base: process.env.E2E_BASE_URL, edge: process.env.E2E_EDGE, db: process.env.E2E_DB, tiles: process.env.E2E_TILE_HOST,
    out: process.env.E2E_OUT || resolve(dirname(fileURLToPath(import.meta.url)), '../out'),
    root: resolve(dirname(fileURLToPath(import.meta.url)), '../../..'), headed: !!process.env.E2E_HEADED, log: process.env.E2E_LOG,
};
export const PASSWORD = 'e2e-pass-12345';
mkdirSync(env.out, { recursive: true });
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let counter = 0;
export const uniq = (p = 'u') => `${p}${Date.now().toString(36)}${(counter++).toString(36)}${Math.random().toString(36).slice(2, 5)}`;

/** Fixture setup against the throwaway database (support/world.php). */
export function world(cmd, data = {}) {
    let out;
    try { out = execFileSync('php', [join(env.root, 'tests/e2e/support/world.php'), cmd, JSON.stringify(data)], { env: process.env, cwd: env.root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }); } catch (e) {
        throw new Error(`world ${cmd} failed: ${String(e.stdout || '').slice(0, 600)} ${String(e.stderr || '').slice(0, 900)}`);
    }
    return JSON.parse(out.trim().split('\n').pop());
}

/** One run of a scheduled command, as the host's cron does it. */
export function artisan(...argv) {
    try { return execFileSync('php', ['artisan', ...argv, '--no-interaction'], { env: process.env, cwd: env.root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }); } catch (e) {
        throw new Error(`artisan ${argv.join(' ')} failed: ${String(e.stdout || '').slice(0, 600)} ${String(e.stderr || '').slice(0, 600)}`);
    }
}

/** Read-only SQL against the app's database (WAL: readers never block the app). */
export function sql(query, params = []) {
    let last;
    for (let attempt = 0; attempt < 8; attempt++) {
        const db = new DatabaseSync(env.db, { readOnly: true });
        try { db.exec('PRAGMA busy_timeout = 5000'); return db.prepare(query).all(...params); } catch (e) {
            last = e;
            if (!/locked|busy/i.test(String(e.message))) throw e;
        } finally { db.close(); }
        Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 250 * (attempt + 1));
    }
    throw last;
}
export const one = (q, p) => sql(q, p)[0] ?? null;

/** Waits until fn() returns something truthy (polling the database or the stubs). */
export async function until(fn, { timeout = 20000, every = 250, what = 'condition' } = {}) {
    const end = Date.now() + timeout;
    let last;
    while (Date.now() < end) {
        last = await fn();
        if (last) return last;
        await sleep(every);
    }
    throw new Error(`timed out waiting for ${what} (last: ${JSON.stringify(last)?.slice(0, 200)})`);
}

// ------------------------------------------------------------------ stubs

export async function stub(body) {
    if (body === undefined) return (await fetch(`${env.edge}/__control`)).json();
    return (await fetch(`${env.edge}/__control`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) })).json();
}
export async function mailsTo(address) { return (await stub()).mails.filter((m) => m.to.includes(address)); }

// Hosts the browser asks for but Playwright answers from the stubs (never contacted): not "third-party hosts".
const routedHosts = new Set();

/** Paystack's hosted checkout inside the browser: checkout.paystack.com is routed to the local stub, never contacted. */
export async function interceptCheckout(context) {
    routedHosts.add('checkout.paystack.com');
    await context.route('https://checkout.paystack.com/**', async (route) => {
        const url = new URL(route.request().url());
        const [code, action] = url.pathname.split('/').filter(Boolean);
        const r = await fetch(`${env.edge}/__control/checkout`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ code, action: action || 'view' }) });
        if (r.status !== 200) return route.fulfill({ status: 404, body: 'unknown checkout' });
        const out = await r.json();
        if (!action) {
            return route.fulfill({ status: 200, contentType: 'text/html', body: `<!doctype html><title>Paystack test checkout</title><h1>Pay R${(out.tx.amount / 100).toFixed(2)}</h1><a id="pay" href="/${code}/pay">Pay</a> <a id="decline" href="/${code}/decline">Decline</a>` });
        }
        return route.fulfill({ status: 302, headers: { location: out.location } });
    });
}

// ------------------------------------------------------------------ people and browsers

let browserPromise = null;
export function browser() {
    browserPromise ??= chromium.launch({ channel: 'chrome', headless: !env.headed, args: ['--disable-features=Translate', '--force-prefers-reduced-motion'] });
    return browserPromise;
}
export async function closeBrowser() { if (browserPromise) { const b = await browserPromise; await b.close(); browserPromise = null; } }

export function makePerson(cmd = 'user', label = 'Person', extra = {}) {
    const email = `${uniq(label.toLowerCase().replace(/\W/g, ''))}@example.test`;
    return { email, password: PASSWORD, name: `${label} ${uniq('').slice(0, 4)}`, ...world(cmd, { name: label, email, password: PASSWORD, ...extra }) };
}

const openContexts = new Set();

/**
 * After a test: screenshots every page still open (a test that failed did not close its contexts) to
 * out/left-<label>-<n>.png, then closes them so the next test starts clean. Returns how many were left open.
 */
export async function closeLeftovers(label) {
    let n = 0;
    for (const ctx of [...openContexts]) {
        for (const page of ctx.pages()) await shot(page, `left-${label.replace(/\W+/g, '-').slice(0, 50)}-${n++}`);
        await ctx.close().catch(() => {});
        openContexts.delete(ctx);
    }
    return n;
}

/** A new context (390 phone by default) with geolocation granted; optionally signed in through the real login form. */
export async function newContext({ user = null, width = 390, height = 844, geo = null, dark = false } = {}) {
    const b = await browser();
    const ctx = await b.newContext({
        // The tile stub is https with a self-signed certificate (production tile hosts are https).
        baseURL: env.base, ignoreHTTPSErrors: true, viewport: { width, height }, colorScheme: dark ? 'dark' : 'light', timezoneId: 'Africa/Johannesburg', locale: 'en-ZA',
        geolocation: geo ? { latitude: geo[0], longitude: geo[1], accuracy: 8 } : undefined, permissions: ['geolocation'],
    });
    openContexts.add(ctx);
    ctx.on('close', () => openContexts.delete(ctx));
    if (user) await signIn(ctx, user);
    return ctx;
}

export async function signIn(ctx, user) {
    const page = await ctx.newPage();
    await page.goto('/login');
    await page.fill('input[name=email]', user.email);
    await page.fill('input[name=password]', user.password || PASSWORD);
    await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 }), page.click('button[type=submit]')]);
    await page.close();
}

/** Watches a page: console errors, page errors, every request URL, server errors, CSP violations. */
export function watch(page, store = {}) {
    store.errors ??= []; store.requests ??= []; store.bad ??= []; store.csp ??= [];
    page.on('console', (m) => {
        if (m.type() === 'error') store.errors.push(`${m.text()} @ ${m.location().url}`);
        if (/Content.Security.Policy/i.test(m.text())) store.csp.push(m.text());
    });
    page.on('pageerror', (e) => store.errors.push(`pageerror: ${e.message}`));
    page.on('request', (r) => { store.requests.push(r.url()); if (/\/csp-report/.test(r.url())) store.csp.push(r.postData() || 'csp report'); });
    page.on('response', (r) => { if (r.status() >= 500) store.bad.push(`${r.status()} ${r.url()}`); });
    return store;
}

/** Hosts a page contacted other than the app itself and the configured (local) tile host. */
export function externalHosts(urls) {
    const allowed = new Set([new URL(env.base).host, env.tiles, ...routedHosts]);
    return [...new Set(urls.filter((u) => /^https?:/.test(u)).map((u) => new URL(u).host).filter((h) => !allowed.has(h)))];
}

export async function shot(page, name) {
    try { await page.screenshot({ path: join(env.out, `${name}.png`) }); } catch { /* page gone */ }
}

/** Runs a named step; on failure takes a screenshot (out/fail-<name>.png) and rethrows with the step name. */
export async function step(page, name, fn) {
    try { return await fn(); } catch (e) {
        await shot(page, `fail-${name.replace(/\W+/g, '-').slice(0, 60)}`);
        e.message = `[step: ${name}] ${e.message}`; throw e;
    }
}

/** JSON call from inside a signed-in page (same session, CSRF token from the page). */
export async function api(page, method, url, body, headers = {}) {
    return page.evaluate(async ([m, u, b, h]) => {
        const token = document.querySelector('meta[name=csrf-token]')?.content;
        const r = await fetch(u, { method: m, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token || '', 'X-Requested-With': 'XMLHttpRequest', ...h }, body: b ? JSON.stringify(b) : undefined, credentials: 'same-origin' });
        let data = null; try { data = await r.json(); } catch { /* not json */ }
        return { status: r.status, data, headers: Object.fromEntries(r.headers.entries()) };
    }, [method, url, body ?? null, headers]);
}

// ------------------------------------------------------------------ the driver's phone

/**
 * Moves the emulated phone along the grid road route from `from` to `to`, one fix per second at `speed` m/s
 * (the driver app keeps one fix per second and posts every 4 s). Stops exactly at `to`.
 */
export async function driveAlong(ctx, from, to, { speed = 25, onStep = null } = {}) {
    const path = roadPath(from, to);
    const total = pathMetres(path);
    for (let travelled = speed; ; travelled += speed) {
        const p = pointAlong(path, Math.min(travelled, total));
        await ctx.setGeolocation({ latitude: p[0], longitude: p[1], accuracy: 8 });
        if (onStep) await onStep(p);
        await sleep(1000);
        if (travelled >= total) break;
    }
    return total;
}

export function laravelLogTail(n = 40) {
    if (!env.log || !existsSync(env.log)) return '';
    return readFileSync(env.log, 'utf8').split('\n').slice(-n).join('\n');
}

/** A real JPEG (made by PHP's GD, as a phone photo would be decoded) for document uploads. */
export function fixtureJpeg() {
    const file = join(env.out, 'fixture-document.jpg');
    if (!existsSync(file)) {
        execFileSync('php', ['-r', '$i = imagecreatetruecolor(640, 420); imagefill($i, 0, 0, imagecolorallocate($i, 230, 226, 214)); imagestring($i, 5, 40, 40, "E2E TEST DOCUMENT", imagecolorallocate($i, 20, 20, 20)); imagejpeg($i, $argv[1], 85);', file]);
    }
    return file;
}
