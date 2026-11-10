// Geometry shared by the routing stub and the specs: the city-grid road route (north-south along the start's
// street, then east-west along the end's street, 20 m samples), polyline5 encoding, great-circle distance.
// The same model as tests/Concerns/AcceptanceWorld.php, so the PHP and browser acceptance layers agree.

export const R = 6371008.8;

export function metres(a, b) {
    const toRad = (d) => (d * Math.PI) / 180;
    const dLat = toRad(b[0] - a[0]);
    const dLng = toRad(b[1] - a[1]);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a[0])) * Math.cos(toRad(b[0])) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

/** @returns {Array<[number, number]>} [lat, lng] points every ~20 m along the grid route. */
export function roadPath(from, to) {
    const corner = [to[0], from[1]];
    const points = [from];
    for (const [a, b] of [[from, corner], [corner, to]]) {
        const steps = Math.max(1, Math.ceil(metres(a, b) / 20));
        for (let i = 1; i <= steps; i++) points.push([a[0] + ((b[0] - a[0]) * i) / steps, a[1] + ((b[1] - a[1]) * i) / steps]);
    }
    return points;
}

export function pathMetres(points) {
    let total = 0;
    for (let i = 1; i < points.length; i++) total += metres(points[i - 1], points[i]);
    return total;
}

export function pointAlong(points, m) {
    for (let i = 1; i < points.length; i++) {
        const seg = metres(points[i - 1], points[i]);
        if (m <= seg) {
            const f = seg > 0 ? m / seg : 0;
            return [points[i - 1][0] + (points[i][0] - points[i - 1][0]) * f, points[i - 1][1] + (points[i][1] - points[i - 1][1]) * f];
        }
        m -= seg;
    }
    return points[points.length - 1];
}

function encodeValue(v) {
    let n = v < 0 ? ~(v << 1) : v << 1;
    let out = '';
    while (n >= 0x20) { out += String.fromCharCode((0x20 | (n & 0x1f)) + 63); n >>= 5; }
    return out + String.fromCharCode(n + 63);
}

export function encodePolyline(points) {
    let lat = 0, lng = 0, out = '';
    for (const [a, b] of points) {
        const la = Math.round(a * 1e5), lo = Math.round(b * 1e5);
        out += encodeValue(la - lat) + encodeValue(lo - lng);
        lat = la; lng = lo;
    }
    return out;
}

/** Cape Town City Bowl, inside the approximate reference service area (docs/R46_GEO.md section 7). */
export const PLACES = {
    'Kloof Street': { at: [-33.94, 18.41], label: 'Kloof Street, Gardens, Cape Town, 8001, South Africa' },
    'Bree Street': { at: [-33.9265, 18.4197], label: 'Bree Street, Cape Town City Centre, Cape Town, 8001, South Africa' },
    'Harbour Road': { at: [-33.9265, 18.439], label: 'Harbour Road, Foreshore, Cape Town, 8001, South Africa' },
    'Vredehoek Avenue': { at: [-33.9445, 18.4235], label: 'Vredehoek Avenue, Vredehoek, Cape Town, 8001, South Africa' },
};
export const PICKUP = PLACES['Kloof Street'].at;
export const DEST = PLACES['Bree Street'].at;
export const FURTHER_EAST = PLACES['Harbour Road'].at;
/** 400 m south of the pickup, on the pickup's street. */
export const DRIVER_START = [-33.9436, 18.41];
export const CITY_SPEED_MS = 8.33;
