// R-46 a11y (dev only, never in production): stand-ins for an OSRM routing server and a Nominatim geocoder so the
// accessibility sweep can drive real booking and trip states without calling a paid or rate-limited provider.
// OSRM: a city-grid road route (north-south, then east-west) at 30 km/h. Nominatim: a small Cape Town gazetteer.
//   node scripts/dev/a11y/geo-stub.mjs [port=8693]
import http from 'node:http';

const PLACES = [
    ['Kloof Street', -33.94, 18.41, 'Kloof Street, Gardens, Cape Town, 8001, South Africa'],
    ['Bree Street', -33.913051, 18.431668, 'Bree Street, Cape Town City Centre, Cape Town, 8001, South Africa'],
    ['Harbour Road', -33.913051, 18.453336, 'Harbour Road, Foreshore, Cape Town, 8001, South Africa'],
    ['V&A Waterfront', -33.9036, 18.4207, 'V&A Waterfront, Cape Town, 8001, South Africa'],
    ['Sea Point Promenade', -33.9155, 18.3895, 'Beach Road, Sea Point, Cape Town, 8005, South Africa'],
    ["Company's Garden", -33.9268, 18.417, "Company's Garden, Cape Town City Centre, Cape Town, 8001, South Africa"],
    ['Observatory', -33.9381, 18.4697, 'Lower Main Road, Observatory, Cape Town, 7925, South Africa'],
];
const R = 6371008.8;
const rad = (d) => (d * Math.PI) / 180;
const hav = (a, b) => { const h = Math.sin(rad(b[0] - a[0]) / 2) ** 2 + Math.cos(rad(a[0])) * Math.cos(rad(b[0])) * Math.sin(rad(b[1] - a[1]) / 2) ** 2; return 2 * R * Math.asin(Math.sqrt(h)); };
function encode(points) {
    let out = ''; let pl = 0; let pg = 0;
    const e = (v) => { v = v < 0 ? ~(v << 1) : v << 1; let s = ''; while (v >= 0x20) { s += String.fromCharCode((0x20 | (v & 0x1f)) + 63); v >>= 5; } return s + String.fromCharCode(v + 63); };
    for (const [la, lg] of points) { const a = Math.round(la * 1e5); const b = Math.round(lg * 1e5); out += e(a - pl) + e(b - pg); pl = a; pg = b; }
    return out;
}
function path(a, b) {
    const corner = [b[0], a[1]]; const pts = [a];
    for (const [s, e] of [[a, corner], [corner, b]]) { const n = Math.max(1, Math.ceil(hav(s, e) / 20)); for (let i = 1; i <= n; i++) pts.push([s[0] + ((e[0] - s[0]) * i) / n, s[1] + ((e[1] - s[1]) * i) / n]); }
    return pts;
}
const send = (res, body, code = 200) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };

http.createServer((req, res) => {
    const u = new URL(req.url, 'http://stub');
    const m = u.pathname.match(/\/route\/v1\/[a-z]+\/([-\d.]+),([-\d.]+);([-\d.]+),([-\d.]+)/);
    if (m) {
        const pts = path([+m[2], +m[1]], [+m[4], +m[3]]);
        let dist = 0; for (let i = 1; i < pts.length; i++) dist += hav(pts[i - 1], pts[i]);
        return send(res, { code: 'Ok', waypoints: [], routes: [{ distance: Math.round(dist * 10) / 10, duration: Math.round((dist / 8.33) * 10) / 10, weight: dist / 8.33, weight_name: 'routability', geometry: encode(pts), legs: [] }] });
    }
    if (u.pathname.endsWith('/search')) {
        const words = (u.searchParams.get('q') || '').toLowerCase().replace(/,/g, ' ').split(/\s+/).filter((w) => w.length > 2 && !['street', 'road', 'cape', 'town'].includes(w));
        const hits = PLACES.filter((p) => words.length && words.every((w) => p[3].toLowerCase().includes(w)));
        return send(res, hits.map((p, i) => ({ place_id: i, licence: 'Data (c) OpenStreetMap contributors, ODbL 1.0.', osm_type: 'way', osm_id: i, lat: String(p[1]), lon: String(p[2]), category: 'highway', type: 'residential', place_rank: 26, importance: 0.1, addresstype: 'road', name: p[0], display_name: p[3], boundingbox: [p[1] - 0.001, p[1] + 0.001, p[2] - 0.001, p[2] + 0.001].map(String) })));
    }
    if (u.pathname.endsWith('/reverse')) {
        const pt = [Number(u.searchParams.get('lat')), Number(u.searchParams.get('lon'))];
        const best = PLACES.reduce((a, p) => (hav(pt, [p[1], p[2]]) < hav(pt, [a[1], a[2]]) ? p : a));
        if (hav(pt, [best[1], best[2]]) > 900) return send(res, { error: 'Unable to geocode' });
        return send(res, { place_id: 1, lat: String(pt[0]), lon: String(pt[1]), name: best[0], display_name: best[3], addresstype: 'road', category: 'highway', type: 'residential' });
    }
    return send(res, { error: 'not found' }, 404);
}).listen(Number(process.argv[2] || 8693), '127.0.0.1');
