// OSRM-compatible routing stub for load tests (DEVELOPMENT ONLY, never a real provider).
//   node scripts/load/stub-osrm.mjs [port=8790]
// Answers GET /route/v1/driving/<lng>,<lat>;<lng>,<lat> with a city-grid road route (north-south, then east-west,
// 30 km/h), like tests/Concerns/AcceptanceWorld. Fault injection for the outage scenarios:
//   GET /__mode?m=ok | down (503) | slow (sleeps 6 s, past the 4 s client timeout) | garbage (invalid JSON)
//   GET /__stats  -> {requests, by_mode}
import http from 'node:http';

const port = Number(process.argv[2] || 8790);
let mode = 'ok';
const stats = { requests: 0, by_mode: {} };

function encode(points) {
    let lastLat = 0;
    let lastLng = 0;
    let out = '';
    const enc = (v) => {
        v = v < 0 ? ~(v << 1) : v << 1;
        let s = '';
        while (v >= 0x20) {
            s += String.fromCharCode((0x20 | (v & 0x1f)) + 63);
            v >>= 5;
        }
        return s + String.fromCharCode(v + 63);
    };
    for (const [lat, lng] of points) {
        const la = Math.round(lat * 1e5);
        const ln = Math.round(lng * 1e5);
        out += enc(la - lastLat) + enc(ln - lastLng);
        lastLat = la;
        lastLng = ln;
    }
    return out;
}

function metres([a, b], [c, d]) {
    const r = 6371008.8;
    const toRad = (x) => (x * Math.PI) / 180;
    const dLat = toRad(c - a);
    const dLng = toRad(d - b);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a)) * Math.cos(toRad(c)) * Math.sin(dLng / 2) ** 2;
    return 2 * r * Math.asin(Math.sqrt(h));
}

function route(from, to) {
    const corner = [to[0], from[1]];
    const pts = [];
    for (const [s, e] of [[from, corner], [corner, to]]) {
        const n = Math.max(1, Math.ceil(metres(s, e) / 50));
        for (let i = 0; i < n; i++) pts.push([s[0] + ((e[0] - s[0]) * i) / n, s[1] + ((e[1] - s[1]) * i) / n]);
    }
    pts.push(to);
    let d = 0;
    for (let i = 1; i < pts.length; i++) d += metres(pts[i - 1], pts[i]);
    return { distance: Math.round(d * 10) / 10, duration: Math.round((d / 8.33) * 10) / 10, geometry: encode(pts) };
}

http.createServer((req, res) => {
    const url = new URL(req.url, 'http://stub');
    if (url.pathname === '/__mode') {
        mode = url.searchParams.get('m') || 'ok';
        res.end(JSON.stringify({ mode }));
        return;
    }
    if (url.pathname === '/__stats') {
        res.end(JSON.stringify({ mode, ...stats }));
        return;
    }
    stats.requests++;
    stats.by_mode[mode] = (stats.by_mode[mode] || 0) + 1;
    const m = url.pathname.match(/\/route\/v1\/driving\/([-0-9.]+),([-0-9.]+);([-0-9.]+),([-0-9.]+)/);
    if (!m) {
        res.statusCode = 400;
        res.end(JSON.stringify({ code: 'InvalidUrl' }));
        return;
    }
    if (mode === 'down') {
        res.statusCode = 503;
        res.end(JSON.stringify({ code: 'InvalidUrl', message: 'Service unavailable' }));
        return;
    }
    if (mode === 'garbage') {
        res.end('<html>gateway error</html>');
        return;
    }
    const answer = () => {
        const r = route([Number(m[2]), Number(m[1])], [Number(m[4]), Number(m[3])]);
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ code: 'Ok', waypoints: [], routes: [{ ...r, weight: r.duration, weight_name: 'routability', legs: [] }] }));
    };
    if (mode === 'slow') setTimeout(answer, 6000);
    else answer();
}).listen(port, '127.0.0.1', () => console.log(`osrm stub on http://127.0.0.1:${port}`));
