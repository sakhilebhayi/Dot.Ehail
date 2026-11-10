// R-46 a11y (dev only): shared helpers for the accessibility scripts. Real Chrome through playwright-core (pinned
// devDependency) and axe-core (pinned devDependency), against the dev server started by serve.sh.
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright-core';

const require = createRequire(import.meta.url);
export const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../../..');
export const OUT = join(ROOT, 'storage/a11y');
mkdirSync(OUT, { recursive: true });
const AXE = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

export const arg = (n, d) => { const i = process.argv.indexOf(`--${n}`); return i > -1 ? process.argv[i + 1] : d; };
export const has = (n) => process.argv.includes(`--${n}`);
export const BASE = arg('base', process.env.BASE || 'http://127.0.0.1:8623');
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
export const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a);
export const HEIGHT = { 320: 640, 360: 780, 390: 844, 430: 932, 768: 1024, 1440: 900 };
export const PASSWORD = 'dev-pass-123';
export const PEOPLE = { passenger: 'dev@example.test', driver: 'a11y-driver@example.test', ops: 'ops-demo@example.test', newcomer: 'a11y-new@example.test' };

// Cape Town points the geo stand-in knows (Kloof Street, Bree Street) and where the driver starts.
export const PICKUP = [-33.94, 18.41];
export const DEST = [-33.913051, 18.431668];
export const START = [-33.949, 18.41];

export async function browser() {
    return chromium.launch({ channel: 'chrome', headless: !has('headed') });
}

/** A signed-in person in their own context (own cookies, own geolocation). Tiles are never fetched by the scripts. */
export async function person(b, email, { width = 390, theme = 'light', geo = PICKUP, reducedMotion = 'no-preference' } = {}) {
    const ctx = await b.newContext({
        viewport: { width, height: HEIGHT[width] || 900 }, deviceScaleFactor: 1, colorScheme: theme === 'dark' ? 'dark' : 'light', reducedMotion,
        isMobile: false, hasTouch: width < 768, bypassCSP: !process.env.A11Y_CSP, // A11Y_CSP=1 keeps the page's own CSP (axe goes in through DevTools, which CSP does not block)
        geolocation: { latitude: geo[0], longitude: geo[1], accuracy: 8 }, permissions: ['geolocation'],
    });
    await ctx.route(/tile\.openstreetmap\.org|tiles?\./, (r) => r.abort());
    await ctx.addInitScript((t) => { try { localStorage.setItem('eh-theme', t); localStorage.setItem('eh-theme-drive', t); } catch (e) { /* storage blocked */ } }, theme);
    const page = await ctx.newPage();
    if (email) {
        await page.goto(`${BASE}/login`);
        await page.fill('input[name=email]', email);
        await page.fill('input[name=password]', PASSWORD);
        await Promise.all([page.waitForNavigation(), page.press('input[name=password]', 'Enter')]);
    }
    const errors = [];
    page.on('pageerror', (e) => errors.push('pageerror ' + e.message));
    page.on('console', (m) => m.type() === 'error' && !/tile|ERR_FAILED|net::/.test(m.text()) && errors.push('console ' + m.text().slice(0, 160)));
    return { ctx, page, errors, api: apiFor(ctx) };
}

/** JSON API as the context's person (cookie session + XSRF), like the app's own fetch helper. */
export function apiFor(ctx) {
    return async (method, path, data) => {
        const xsrf = decodeURIComponent((await ctx.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value || '');
        const r = await ctx.request.fetch(BASE + path, { method, data, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest' } });
        let body = null; try { body = await r.json(); } catch { /* not json */ }
        return { status: r.status(), body };
    };
}

/** Switch the product's theme on the open page without a reload (storage, attribute, class and media all agree). */
export async function setTheme(page, theme) {
    await page.emulateMedia({ colorScheme: theme === 'dark' ? 'dark' : 'light' });
    await page.evaluate((t) => {
        try { localStorage.setItem('eh-theme', t); localStorage.setItem('eh-theme-drive', t); } catch (e) { /* storage blocked */ }
        const r = document.documentElement;
        if (r.hasAttribute('data-theme-lock')) return;
        r.dataset.theme = t; r.classList.toggle('dark', t === 'dark');
        document.dispatchEvent(new CustomEvent('eh:theme', { detail: { theme: t } }));
    }, theme);
}

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];
export const IMPACT = { critical: 4, serious: 3, moderate: 2, minor: 1 };

/** axe-core on the settled page. Map tiles are the provider's picture, not the interface; everything else is judged. */
export async function axe(page) {
    await page.evaluate(() => Promise.race([Promise.all(document.getAnimations().map((a) => a.finished.catch(() => {}))), new Promise((r) => setTimeout(r, 2500))]));
    if (!(await page.evaluate(() => typeof window.axe === 'object'))) await page.evaluate(AXE);
    return page.evaluate(async (tags) => {
        const r = await window.axe.run({ include: [document], exclude: [['.leaflet-tile-pane']] }, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] });
        return r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length, sample: v.nodes.slice(0, 4).map((n) => ({ target: n.target.join(' '), html: n.html.slice(0, 160), why: (n.any.concat(n.all, n.none)[0] || {}).message })) }));
    }, TAGS);
}

/** Facts axe does not report: horizontal overflow, h1 count, duplicate ids, small targets. */
export async function facts(page, { minTarget = 24 } = {}) {
    return page.evaluate((min) => {
        const visible = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && !e.closest('[hidden],[inert]'); };
        const small = [...document.querySelectorAll('a[href],button,input:not([type=hidden]),select,textarea,summary,[role=button],[role=tab]')]
            .filter((e) => visible(e) && !e.closest('.leaflet-control-attribution,p,li:not([role]),dd,td,.eh-prose') && !e.matches('input[type=radio],input[type=checkbox]'))
            .filter((e) => { const r = e.getBoundingClientRect(); return r.height < min || r.width < min; })
            .map((e) => `${e.tagName.toLowerCase()}${e.className && typeof e.className === 'string' ? '.' + e.className.split(' ')[0] : ''}[${(e.getAttribute('aria-label') || e.textContent || '').trim().slice(0, 24)}] ${Math.round(e.getBoundingClientRect().width)}x${Math.round(e.getBoundingClientRect().height)}`);
        const ids = new Map(); for (const e of document.querySelectorAll('[id]')) ids.set(e.id, (ids.get(e.id) || 0) + 1);
        return {
            overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
            h1: [...document.querySelectorAll('h1')].length,
            dupIds: [...ids].filter(([, n]) => n > 1).map(([id]) => id),
            small: small.slice(0, 8), smallCount: small.length,
            path: location.pathname, title: document.title,
        };
    }, minTarget);
}

export function php(script, args = []) {
    return execFileSync('php', [join(ROOT, script), ...args], { cwd: ROOT, encoding: 'utf8' });
}

export function writeJson(name, data) { const p = join(OUT, name); writeFileSync(p, JSON.stringify(data, null, 2)); return p; }

/** The driver's phone: post positions along a city-grid path, as geo expects (three steady fixes first, D-208). */
export function driverPhone(api) {
    const pos = (lat, lng, speed = 0) => api('POST', '/geo/driver/location', { samples: [{ lat, lng, accuracy: 8, speed, recorded_at: Date.now() }] });
    return {
        pos,
        async settle(at) { for (let i = 0; i < 3; i++) { await pos(...at); await sleep(350); } },
        async drive(a, c, seconds = 9, every = 3) {
            const corner = [c[0], a[1]]; const legs = [[a, corner], [corner, c]];
            const len = ([p, q]) => Math.hypot(p[0] - q[0], p[1] - q[1]); const total = len(legs[0]) + len(legs[1]);
            const steps = Math.max(1, Math.floor(seconds / every));
            for (let i = 1; i <= steps; i++) {
                let d = (total * i) / steps; let p = c;
                for (const [s, e] of legs) { const L = len([s, e]); if (d <= L) { const f = L ? d / L : 0; p = [s[0] + (e[0] - s[0]) * f, s[1] + (e[1] - s[1]) * f]; break; } d -= L; }
                await pos(p[0], p[1], 8.3); await sleep(every * 1000);
            }
        },
    };
}

/** The place search (geo's combobox) driven by the keyboard, as a person would: type, Enter to search, pick. */
export async function search(page, field, text) {
    const input = page.locator(field);
    await input.click(); await input.fill(text);
    await input.press('Enter'); // the geocoder does not allow search-as-you-type: Enter searches
    await page.waitForSelector('[role=listbox] [role=option]', { timeout: 8000 }).catch(() => {});
    if (!(await input.getAttribute('aria-activedescendant'))) await input.press('ArrowDown');
    await input.press('Enter');
    await sleep(700);
}

/** Rides left going by an earlier run (dev database) are cancelled by the operator, as operations would. */
export async function clearRides(b) {
    const left = JSON.parse(php('scripts/dev/a11y/ids.php')).active || [];
    if (!left.length) return;
    const ops = await person(b, PEOPLE.ops);
    for (const id of left) log('force-cancel left-over ride', id, (await ops.api('POST', `/api/ehail/ops/rides/${id}/force-cancel`, { reason: 'Accessibility checks: clearing a dev ride left by an earlier run.' })).status);
    await ops.ctx.close();
}
