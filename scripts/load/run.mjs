// Dot.Ehail load runner (DEVELOPMENT ONLY; docs/R46_PERF.md). Plays every online driver's phone and every
// passenger's app against a running server, following the real polling protocol, and records per-endpoint
// latency, status codes, bytes, 304 share and the server's own cost (X-Ehail-* probe headers).
//
//   node scripts/load/run.mjs --world world.json --base http://127.0.0.1:8621 [--protocol legacy|sync]
//        [--duration 90] [--drivers 500] [--requests 100] [--request-at 10] [--moving 0.3]
//        [--double-tap 0.2] [--decline 0.15] [--osrm http://127.0.0.1:8790] [--outage-at 40 --outage-for 15]
//        [--out result.json]
//
// legacy: what the driver app did before R-46 perf (feed GET every Retry-After, location POST every 4 s, heartbeat
//         every 10 s). sync: one POST /api/ehail/driver/sync carrying positions and returning the feed.
import fs from 'node:fs';
import http from 'node:http';

const args = Object.fromEntries(process.argv.slice(2).reduce((acc, a, i, all) => (a.startsWith('--') ? [...acc, [a.slice(2), all[i + 1] && !all[i + 1].startsWith('--') ? all[i + 1] : 'true']] : acc), []));
const world = JSON.parse(fs.readFileSync(args.world, 'utf8'));
const BASE = args.base || 'http://127.0.0.1:8621';
const PROTOCOL = args.protocol || 'sync';
const DURATION = Number(args.duration || 90) * 1000;
const DRIVERS = world.drivers.slice(0, Number(args.drivers || world.drivers.length));
const REQUESTS = Number(args.requests ?? 100);
const REQUEST_AT = Number(args['request-at'] ?? 10) * 1000;
const MOVING = Number(args.moving ?? 0.3);
const DOUBLE_TAP = Number(args['double-tap'] ?? 0.2);
const DECLINE = Number(args.decline ?? 0.15);
const OSRM = args.osrm || null;
const OUTAGE_AT = args['outage-at'] ? Number(args['outage-at']) * 1000 : null;
const OUTAGE_FOR = Number(args['outage-for'] ?? 15) * 1000;

// One shared pool: at most this many requests in flight at once (the rest queue in the client, which is where a
// phone's request would wait for a free PHP worker anyway). Latencies below include that wait.
const agent = new http.Agent({ keepAlive: true, maxSockets: Number(args.sockets || 128) });

const t0 = Date.now();
const elapsed = () => Date.now() - t0;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const rnd = (a, b) => a + Math.random() * (b - a);
let stopping = false;

// ---------------------------------------------------------------- metrics
const stats = {};
const errors = [];
function record(name, { ms, status, bytes, headers }) {
    const s = (stats[name] ??= { n: 0, ms: [], status: {}, bytes: 0, q: 0, w: 0, serverMs: 0, probed: 0 });
    s.n++;
    s.ms.push(ms);
    s.status[status] = (s.status[status] || 0) + 1;
    s.bytes += bytes;
    const q = headers.get('x-ehail-queries');
    if (q !== null) {
        s.probed++;
        s.q += Number(q);
        s.w += Number(headers.get('x-ehail-writes') || 0);
        s.serverMs += Number(headers.get('x-ehail-ms') || 0);
    }
}
const pct = (arr, p) => {
    if (!arr.length) return 0;
    const sorted = [...arr].sort((a, b) => a - b);
    return Math.round(sorted[Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length))] * 10) / 10;
};

function request(path, { method, headers, body }) {
    return new Promise((resolve, reject) => {
        const req = http.request(BASE + path, { method, headers, agent, timeout: 30000 }, (res) => {
            const chunks = [];
            res.on('data', (c) => chunks.push(c));
            res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, text: Buffer.concat(chunks).toString('utf8'), rawHeaders: res.rawHeaders }));
        });
        req.on('timeout', () => req.destroy(new Error('client timeout 30 s')));
        req.on('error', reject);
        if (body !== undefined) req.write(body);
        req.end();
    });
}

const headerReader = (h) => ({ get: (k) => (h[k.toLowerCase()] === undefined ? null : String(h[k.toLowerCase()])) });

async function call(name, who, path, { method = 'GET', body, etag } = {}) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', Cookie: who.cookie, 'User-Agent': 'ehail-load/1.0' };
    if (method !== 'GET') headers['X-CSRF-TOKEN'] = who.csrf;
    const payload = body === undefined ? undefined : JSON.stringify(body);
    if (payload !== undefined) {
        headers['Content-Type'] = 'application/json';
        headers['Content-Length'] = Buffer.byteLength(payload);
    }
    if (etag) headers['If-None-Match'] = etag;
    const started = performance.now();
    try {
        const res = await request(path, { method, headers, body: payload });
        const ms = performance.now() - started;
        const h = headerReader(res.headers);
        // What a phone pays on the wire for the answer: body plus response headers (cookies included).
        const headerBytes = res.rawHeaders.reduce((a, v) => a + v.length + 2, 0);
        record(name, { ms, status: res.status, bytes: Buffer.byteLength(res.text) + headerBytes, headers: h });
        stats[name].up = (stats[name].up || 0) + (payload ? Buffer.byteLength(payload) : 0) + 600;
        let data = null;
        try {
            data = res.text ? JSON.parse(res.text) : null;
        } catch {
            data = null;
        }
        if (res.status >= 500) errors.push({ at: elapsed(), name, status: res.status, body: res.text.slice(0, 200) });
        return { status: res.status, data, headers: h };
    } catch (e) {
        record(name, { ms: performance.now() - started, status: 'neterr', bytes: 0, headers: headerReader({}) });
        errors.push({ at: elapsed(), name, status: 'neterr', body: String(e.message).slice(0, 200) });
        return { status: 0, data: null, headers: headerReader({}) };
    }
}

// ---------------------------------------------------------------- shared trip state (the PIN goes from passenger to driver)
const pins = new Map(); // ride id -> pin
const outcome = { requested: 0, request_refused: {}, quotes_unavailable: 0, assigned: 0, started: 0, completed: 0, accept_conflicts: 0, double_taps: 0 };

// ---------------------------------------------------------------- drivers
function makeDriver(d, i) {
    return { ...d, i, version: 0, moving: Math.random() < MOVING, heading: Math.floor(rnd(0, 360)), lastFixAt: 0, lastLocPost: 0, lastHeartbeat: 0, ride: null, offerSeen: null, queue: [], stage: null };
}

function fix(drv, toward = null) {
    const now = Date.now();
    if (toward) {
        const dLat = toward[0] - drv.lat;
        const dLng = toward[1] - drv.lng;
        const dist = Math.hypot(dLat, dLng);
        const step = 0.00015; // ~15 m a second
        if (dist > step) {
            drv.lat += (dLat / dist) * step * 3;
            drv.lng += (dLng / dist) * step * 3;
        }
    } else if (drv.moving) {
        drv.lat += Math.cos((drv.heading * Math.PI) / 180) * 0.0003;
        drv.lng += Math.sin((drv.heading * Math.PI) / 180) * 0.0003;
        if (Math.random() < 0.1) drv.heading = (drv.heading + rnd(-60, 60) + 360) % 360;
    }
    return { lat: Number(drv.lat.toFixed(7)), lng: Number(drv.lng.toFixed(7)), accuracy: Math.round(rnd(4, 15)), speed: drv.moving || toward ? 8.3 : 0, heading: Math.round(drv.heading), recorded_at: now };
}

async function handleFeed(drv, body) {
    if (!body) return;
    if (body.version) drv.version = body.version;
    const offer = body.offer;
    drv.ride = body.ride ?? null;
    if (offer && drv.offerSeen !== offer.id) {
        drv.offerSeen = offer.id;
        respondToOffer(drv, offer);
    }
    if (drv.ride) advanceTrip(drv);
}

async function respondToOffer(drv, offer) {
    await sleep(rnd(800, 3500));
    if (stopping) return;
    if (Math.random() < DECLINE) {
        await call('offer.decline', drv, `/api/ehail/offers/${offer.id}/decline`, { method: 'POST', body: { reason: 'too_far' } });
        return;
    }
    const tap = () => call('offer.accept', drv, `/api/ehail/offers/${offer.id}/accept`, { method: 'POST', body: {} });
    let results;
    if (Math.random() < DOUBLE_TAP) {
        outcome.double_taps++;
        results = await Promise.all([tap(), tap()]);
    } else {
        results = [await tap()];
    }
    if (results.some((r) => r.status === 200 && r.data?.ride)) outcome.assigned++;
    outcome.accept_conflicts += results.filter((r) => r.status === 409).length;
    const won = results.find((r) => r.status === 200 && r.data);
    if (won) await handleFeed(drv, won.data);
}

let stageBusy = new Set();
async function advanceTrip(drv) {
    const ride = drv.ride;
    if (!ride || stageBusy.has(drv.id)) return;
    stageBusy.add(drv.id);
    try {
        const path = (stage) => `/api/ehail/driver/rides/${ride.id}/${stage}`;
        if (ride.status === 'driver_assigned') {
            await sleep(rnd(500, 1500));
            await call('trip.stage', drv, path('arriving'), { method: 'POST', body: {} });
        } else if (ride.status === 'driver_arriving') {
            // Drive to the pickup (the phone posts fixes along the way in the main loop).
            drv.target = [ride.pickup.lat, ride.pickup.lng];
            if (Math.hypot(drv.lat - ride.pickup.lat, drv.lng - ride.pickup.lng) < 0.0008 || drv.stageSince && Date.now() - drv.stageSince > 15000) {
                drv.lat = ride.pickup.lat;
                drv.lng = ride.pickup.lng;
                await postFixNow(drv);
                const r = await call('trip.stage', drv, path('arrived'), { method: 'POST', body: { confirm_far: true } });
                if (r.status === 200) drv.stageSince = null;
            } else drv.stageSince ??= Date.now();
        } else if (ride.status === 'driver_arrived') {
            const pin = pins.get(ride.id);
            if (pin) {
                const r = await call('trip.stage', drv, path('start'), { method: 'POST', body: { pin } });
                if (r.status === 200) outcome.started++;
            }
        } else if (ride.status === 'in_progress') {
            drv.target = [ride.dropoff.lat, ride.dropoff.lng];
            drv.tripStart ??= Date.now();
            if (Date.now() - drv.tripStart > rnd(15000, 25000)) {
                drv.lat = ride.dropoff.lat;
                drv.lng = ride.dropoff.lng;
                await postFixNow(drv);
                const r = await call('trip.stage', drv, path('complete'), { method: 'POST', body: {} });
                if (r.status === 200) {
                    outcome.completed++;
                    drv.tripStart = null;
                    drv.target = null;
                }
            }
        }
    } finally {
        stageBusy.delete(drv.id);
    }
}

async function postFixNow(drv) {
    const f = fix(drv);
    if (PROTOCOL === 'sync') await call('driver.sync', drv, '/api/ehail/driver/sync', { method: 'POST', body: { samples: [f] } });
    else await call('geo.location', drv, '/geo/driver/location', { method: 'POST', body: { samples: [f] } });
}

async function driverLoopLegacy(drv) {
    await sleep(rnd(0, 2000));
    let nextFeed = 0;
    while (!stopping) {
        const now = Date.now();
        if (now >= nextFeed) {
            const r = await call('driver.feed', drv, '/api/ehail/driver/feed', { etag: drv.version ? `"v${drv.version}"` : undefined });
            if (r.status === 200) await handleFeed(drv, r.data);
            nextFeed = Date.now() + (Number(r.headers.get('retry-after')) || 2) * 1000;
        }
        if (now - drv.lastLocPost >= 4000) {
            drv.lastLocPost = now;
            const r = await call('geo.location', drv, '/geo/driver/location', { method: 'POST', body: { samples: [fix(drv, drv.target)] } });
            if (r.status === 429) drv.lastLocPost = now + 1000;
        }
        if (now - drv.lastHeartbeat >= 10000) {
            drv.lastHeartbeat = now;
            const f = fix(drv, drv.target);
            await call('driver.heartbeat', drv, '/api/ehail/driver/heartbeat', { method: 'POST', body: { lat: f.lat, lng: f.lng, heading: f.heading, speed_ms: f.speed, accuracy_m: f.accuracy } });
        }
        await sleep(250);
    }
}

async function driverLoopSync(drv) {
    await sleep(rnd(0, 3000));
    while (!stopping) {
        const now = Date.now();
        const movingNow = drv.moving || drv.target;
        // The app sends a fix with every sync while moving; parked, one every 15 s keeps the driver matchable.
        const samples = movingNow || now - drv.lastLocPost >= 15000 ? [fix(drv, drv.target)] : [];
        if (samples.length) drv.lastLocPost = now;
        const r = await call('driver.sync', drv, '/api/ehail/driver/sync', { method: 'POST', body: { samples, moving: Boolean(movingNow) }, etag: drv.version ? `"v${drv.version}"` : undefined });
        if (r.status === 200 && r.data?.feed) await handleFeed(drv, r.data.feed);
        else if (r.status === 200 && drv.ride) advanceTrip(drv);
        const wait = (Number(r.headers.get('retry-after')) || 3) * 1000;
        await sleep(r.status === 429 ? 2000 : wait);
    }
}

// ---------------------------------------------------------------- passengers
async function passengerJourney(p) {
    const q = await call('pricing.quote', p, '/pricing/quotes', { method: 'POST', body: { pickup_lat: p.pickup[0], pickup_lng: p.pickup[1], dropoff_lat: p.dropoff[0], dropoff_lng: p.dropoff[1], category: 'standard' } });
    if (q.status !== 200 || !q.data?.available) {
        outcome.quotes_unavailable++;
        return;
    }
    const key = `load-${p.id}-${t0}`;
    const r = await call('rides.request', p, '/api/ehail/rides', { method: 'POST', body: { quote_id: q.data.quote_id, payment_method: 'cash', pickup_address: 'Load pickup', dropoff_address: 'Load drop-off' }, etag: undefined });
    if (r.status !== 201 && r.status !== 200) {
        outcome.request_refused[r.data?.error || r.status] = (outcome.request_refused[r.data?.error || r.status] || 0) + 1;
        return;
    }
    outcome.requested++;
    const rideId = r.data?.ride?.id ?? r.data?.id;
    let version = 0;
    while (!stopping) {
        const f = await call('rides.feed', p, `/api/ehail/rides/${rideId}/feed`, { etag: version ? `"v${version}"` : undefined });
        if (f.status === 200 && f.data) {
            version = f.data.version || version;
            if (f.data.ride?.pin) pins.set(rideId, f.data.ride.pin);
            if (f.data.ride && ['completed', 'expired', 'cancelled_by_passenger', 'cancelled_by_driver', 'cancelled_by_operator', 'failed', 'no_show'].includes(f.data.ride.status)) return;
        }
        await sleep((Number(f.headers.get('retry-after')) || 3) * 1000);
    }
}

// ---------------------------------------------------------------- side load: operators and the scheduler
async function operatorLoop() {
    if (!world.operator) return;
    while (!stopping) {
        await call('ops.live.feed', world.operator, '/ops/live/feed');
        await sleep(10000);
    }
}

async function outage() {
    if (!OSRM || OUTAGE_AT === null) return;
    await sleep(OUTAGE_AT);
    await fetch(`${OSRM}/__mode?m=down`);
    console.log(`[${(elapsed() / 1000).toFixed(0)} s] routing provider DOWN`);
    await sleep(OUTAGE_FOR);
    await fetch(`${OSRM}/__mode?m=ok`);
    console.log(`[${(elapsed() / 1000).toFixed(0)} s] routing provider back`);
}

// ---------------------------------------------------------------- run
const drivers = DRIVERS.map(makeDriver);
console.log(`protocol=${PROTOCOL} drivers=${drivers.length} passengers=${REQUESTS} duration=${DURATION / 1000}s base=${BASE}`);
const loops = drivers.map((d) => (PROTOCOL === 'sync' ? driverLoopSync(d) : driverLoopLegacy(d)));
loops.push(operatorLoop(), outage());
const journeys = [];
setTimeout(() => {
    console.log(`[${(elapsed() / 1000).toFixed(0)} s] ${REQUESTS} passengers request at once`);
    for (const p of world.passengers.slice(0, REQUESTS)) journeys.push(passengerJourney(p));
}, REQUEST_AT);

const ticker = setInterval(() => {
    const total = Object.values(stats).reduce((a, s) => a + s.n, 0);
    console.log(`[${(elapsed() / 1000).toFixed(0)} s] requests=${total} rps=${(total / (elapsed() / 1000)).toFixed(0)} requested=${outcome.requested} assigned=${outcome.assigned} started=${outcome.started} completed=${outcome.completed} errors=${errors.length}`);
}, 10000);

await sleep(DURATION);
stopping = true;
clearInterval(ticker);
await Promise.race([Promise.allSettled([...loops, ...journeys]), sleep(8000)]);

const seconds = elapsed() / 1000;
const table = Object.entries(stats).sort((a, b) => b[1].n - a[1].n).map(([name, s]) => ({
    endpoint: name,
    n: s.n,
    per_s: Number((s.n / seconds).toFixed(1)),
    p50_ms: pct(s.ms, 50),
    p95_ms: pct(s.ms, 95),
    p99_ms: pct(s.ms, 99),
    status: s.status,
    avg_bytes: Math.round(s.bytes / s.n),
    queries: s.probed ? Number((s.q / s.probed).toFixed(1)) : null,
    writes: s.probed ? Number((s.w / s.probed).toFixed(2)) : null,
    server_ms: s.probed ? Number((s.serverMs / s.probed).toFixed(1)) : null,
}));
const total = table.reduce((a, r) => a + r.n, 0);
const driverSide = Object.entries(stats).filter(([n]) => n.startsWith('driver.') || n.startsWith('geo.') || n.startsWith('offer.') || n.startsWith('trip.'));
const driverBytes = driverSide.reduce((a, [, s]) => a + s.bytes, 0);
const driverUp = driverSide.reduce((a, [, s]) => a + (s.up || 0), 0);
const result = {
    protocol: PROTOCOL, drivers: drivers.length, passengers: REQUESTS, seconds: Math.round(seconds), total_requests: total, rps: Number((total / seconds).toFixed(1)),
    driver_requests_per_min: Number((table.filter((r) => r.endpoint.startsWith('driver.') || r.endpoint.startsWith('geo.')).reduce((a, r) => a + r.n, 0) / drivers.length / (seconds / 60)).toFixed(1)),
    // HTTP payload only (no TLS or TCP overhead): download = response headers + body; upload = body + ~600 B of request headers.
    driver_download_kb_per_hour: Number((driverBytes / drivers.length / (seconds / 3600) / 1024).toFixed(0)),
    driver_upload_kb_per_hour: Number((driverUp / drivers.length / (seconds / 3600) / 1024).toFixed(0)),
    outcome, errors_total: errors.length, errors_sample: errors.slice(0, 10), endpoints: table,
};
console.table(table.map(({ status, ...r }) => ({ ...r, status: JSON.stringify(status) })));
console.log(JSON.stringify({ ...result, endpoints: undefined }, null, 2));
if (args.out) fs.writeFileSync(args.out, JSON.stringify(result, null, 2));
process.exit(0);
