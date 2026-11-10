// Front-end cost on a throttled mid-range Android profile (DEVELOPMENT ONLY; docs/R46_PERF.md section 4).
//
//   PLAYWRIGHT_CORE=<path to playwright-core> node scripts/load/frontend.mjs --base http://127.0.0.1:8625 \
//       --cookie '<name=value>' --path /drive [--minutes 2] [--move] [--hidden-after 60] [--out result.json]
//
// Headless Chrome (system Chrome via playwright-core; no project dependency), CPU throttled 4x and the DevTools
// "Fast 3G" network (562.5 ms RTT, 1.47 Mbps down, 675 kbps up), 390 x 844 viewport, a touch phone user agent.
// Phase 1, load: FCP, LCP, DOMContentLoaded, load, total blocking time, a TTI estimate (end of the last long task
// before a 5 s quiet window), bytes by type, and whether the map chunk was fetched. Phase 2, steady state for
// --minutes: requests and bytes per minute up and down (headers included), main-thread CPU per minute, and the JS
// heap, DOM nodes and event listeners sampled each minute (a leak shows as a steady climb). --move walks the
// emulated GPS position 15 m every second; --hidden-after emulates the tab going to the background.
import fs from 'node:fs';
import path from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).reduce((acc, a, i, all) => (a.startsWith('--') ? [...acc, [a.slice(2), all[i + 1] && !all[i + 1].startsWith('--') ? all[i + 1] : 'true']] : acc), []));
const core = process.env.PLAYWRIGHT_CORE || path.join(process.env.HOME, '.npm-global/lib/node_modules/@playwright/cli/node_modules/playwright-core');
const { chromium } = await import(path.join(core, 'index.mjs'));

const BASE = args.base || 'http://127.0.0.1:8625';
const MINUTES = Number(args.minutes || 2);
const host = new URL(BASE).hostname;
const [cookieName, ...cookieRest] = (args.cookie || '').split('=');

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    userAgent: 'Mozilla/5.0 (Linux; Android 13; SM-A346E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
    geolocation: { latitude: -33.9249, longitude: 18.4241, accuracy: 8 },
    permissions: ['geolocation'],
});
if (cookieName) await context.addCookies([{ name: cookieName, value: decodeURIComponent(cookieRest.join('=')), domain: host, path: '/', httpOnly: true, sameSite: 'Lax' }]);

// Map tiles are never fetched from the real tile server by this script (OSMF policy: no automated traffic):
// they are answered locally with a 1x1 image and counted. Real raster tiles are ~10-40 KB each.
let tileRequests = 0;
await context.route(/tile\.openstreetmap\.org|\/tiles?\//, (route) => {
    tileRequests++;
    route.fulfill({ status: 200, contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64') });
});

const page = await context.newPage();
const cdp = await context.newCDPSession(page);
await cdp.send('Network.enable');
await cdp.send('Performance.enable');
await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 562.5, downloadThroughput: (1474.56 * 1024) / 8, uploadThroughput: (675 * 1024) / 8 });

// ---------------------------------------------------------------- network accounting
const requests = new Map();
let window = { reqs: 0, down: 0, up: 0, byPath: {} };
const all = [];
cdp.on('Network.requestWillBeSent', (e) => {
    const headerBytes = Object.entries(e.request.headers).reduce((a, [k, v]) => a + k.length + String(v).length + 4, 0) + e.request.method.length + e.request.url.length + 12;
    requests.set(e.requestId, { url: e.request.url, type: e.type, headerGuess: headerBytes, post: e.request.postData ? Buffer.byteLength(e.request.postData) : 0, at: Date.now() });
});
cdp.on('Network.requestWillBeSentExtraInfo', (e) => {
    // The real request headers (cookies included) as sent.
    const r = requests.get(e.requestId);
    const bytes = Object.entries(e.headers).reduce((a, [k, v]) => a + k.length + String(v).length + 4, 0);
    if (r) r.upHeaders = bytes;
});
cdp.on('Network.responseReceived', (e) => {
    const r = requests.get(e.requestId);
    if (r) {
        r.status = e.response.status;
        r.mime = e.response.mimeType;
    }
});
cdp.on('Network.loadingFinished', (e) => {
    const r = requests.get(e.requestId);
    if (!r) return;
    r.down = e.encodedDataLength;
    const up = (r.upHeaders ?? r.headerGuess) + r.post;
    all.push({ ...r, upTotal: up });
    window.reqs++;
    window.down += r.down;
    window.up += up;
    const p = new URL(r.url).pathname.replace(/\d+/g, ':id');
    window.byPath[p] = (window.byPath[p] || 0) + 1;
});

// ---------------------------------------------------------------- phase 1: load
await page.addInitScript(() => {
    window.__perf = { lcp: 0, longTasks: [] };
    new PerformanceObserver((l) => l.getEntries().forEach((e) => (window.__perf.lcp = e.startTime))).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((l) => l.getEntries().forEach((e) => window.__perf.longTasks.push([e.startTime, e.duration]))).observe({ type: 'longtask', buffered: true });
});
const t0 = Date.now();
const response = await page.goto(BASE + (args.path || '/drive'), { waitUntil: 'load', timeout: 120000 });
await page.waitForTimeout(6000); // the 5 s quiet window for the TTI estimate
const load = await page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0];
    const fcp = performance.getEntriesByName('first-contentful-paint')[0]?.startTime ?? null;
    const lt = window.__perf.longTasks;
    const tbt = lt.reduce((a, [s, d]) => a + Math.max(0, d - 50), 0);
    let tti = Math.max(fcp ?? 0, nav.domContentLoadedEventEnd);
    for (const [s, d] of lt) if (s + d > tti && s >= (fcp ?? 0)) tti = s + d;
    return { fcp_ms: Math.round(fcp), lcp_ms: Math.round(window.__perf.lcp), dcl_ms: Math.round(nav.domContentLoadedEventEnd), load_ms: Math.round(nav.loadEventEnd), tbt_ms: Math.round(tbt), tti_estimate_ms: Math.round(tti), long_tasks: lt.length, title: document.title };
});
const byType = {};
for (const r of all) {
    const k = r.type || 'Other';
    byType[k] = byType[k] || { count: 0, kb: 0 };
    byType[k].count++;
    byType[k].kb += r.down / 1024;
}
Object.values(byType).forEach((v) => (v.kb = Math.round(v.kb * 10) / 10));
const mapChunk = all.filter((r) => /map-[\w-]+\.js|leaflet/i.test(r.url)).map((r) => ({ url: new URL(r.url).pathname, kb: Math.round(r.down / 102.4) / 10 }));
const tiles = tileRequests;
console.log(`status ${response.status()} ${load.title}`);
console.log('load', JSON.stringify(load));
console.log('bytes by type', JSON.stringify(byType), 'map chunks', JSON.stringify(mapChunk), 'tiles', tiles);

// ---------------------------------------------------------------- phase 2: steady state
let lat = -33.9249;
let moveTimer = null;
if (args.move) {
    moveTimer = setInterval(() => {
        lat += 0.000135; // ~15 m north a second
        context.setGeolocation({ latitude: lat, longitude: 18.4241, accuracy: 8 }).catch(() => {});
    }, 1000);
}
const metric = async () => Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((m) => [m.name, m.value]));
const samples = [];
let previous = await metric();
window = { reqs: 0, down: 0, up: 0, byPath: {} };
for (let minute = 1; minute <= MINUTES; minute++) {
    if (args['hidden-after'] && minute * 60 > Number(args['hidden-after']) && !samples.some((s) => s.hidden)) {
        await cdp.send('Emulation.setFocusEmulationEnabled', { enabled: false }).catch(() => {});
        await page.evaluate(() => {
            Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
            Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
            document.dispatchEvent(new Event('visibilitychange'));
        });
    }
    await page.waitForTimeout(60000);
    await cdp.send('HeapProfiler.collectGarbage').catch(() => {});
    const now = await metric();
    const hidden = await page.evaluate(() => document.hidden);
    samples.push({
        minute,
        hidden,
        requests: window.reqs,
        down_kb: Math.round(window.down / 102.4) / 10,
        up_kb: Math.round(window.up / 102.4) / 10,
        cpu_ms: Math.round((now.TaskDuration - previous.TaskDuration) * 1000),
        script_ms: Math.round((now.ScriptDuration - previous.ScriptDuration) * 1000),
        heap_mb: Math.round((now.JSHeapUsedSize / 1048576) * 100) / 100,
        nodes: now.Nodes,
        listeners: now.JSEventListeners,
        by_path: window.byPath,
        tiles: tileRequests,
    });
    console.log('minute', JSON.stringify(samples[samples.length - 1]));
    previous = now;
    window = { reqs: 0, down: 0, up: 0, byPath: {} };
}
clearInterval(moveTimer);
const visible = samples.filter((s) => !s.hidden);
const avg = (k, list = visible) => (list.length ? Math.round((list.reduce((a, s) => a + s[k], 0) / list.length) * 10) / 10 : null);
const result = {
    path: args.path, status: response.status(), load, bytes_by_type: byType, map_chunks: mapChunk, tiles_on_load: tiles,
    steady: { minutes: MINUTES, requests_per_min: avg('requests'), down_kb_per_min: avg('down_kb'), up_kb_per_min: avg('up_kb'), cpu_ms_per_min: avg('cpu_ms'),
        mb_per_hour: Math.round(((avg('down_kb') + avg('up_kb')) * 60 / 1024) * 100) / 100,
        heap_first_mb: samples[0]?.heap_mb, heap_last_mb: samples.at(-1)?.heap_mb, nodes_first: samples[0]?.nodes, nodes_last: samples.at(-1)?.nodes, listeners_first: samples[0]?.listeners, listeners_last: samples.at(-1)?.listeners,
        hidden: samples.filter((s) => s.hidden).length ? { requests_per_min: avg('requests', samples.filter((s) => s.hidden)), cpu_ms_per_min: avg('cpu_ms', samples.filter((s) => s.hidden)) } : null },
    samples,
};
console.log(JSON.stringify({ ...result, samples: undefined }, null, 2));
if (args.out) fs.writeFileSync(args.out, JSON.stringify(result, null, 2));
await browser.close();
