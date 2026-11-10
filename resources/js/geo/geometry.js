// Pure geometry helpers shared by the map module. No DOM, no Leaflet: tested with node --test.

export const EARTH_RADIUS_M = 6371008.8;

const toRad = (deg) => (deg * Math.PI) / 180;
const toDeg = (rad) => (rad * 180) / Math.PI;

/** Great-circle distance in metres between {lat, lng} points. */
export function haversine(a, b) {
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;

    return 2 * EARTH_RADIUS_M * Math.asin(Math.min(1, Math.sqrt(h)));
}

/** Initial bearing from a to b, degrees clockwise from north in [0, 360). */
export function bearing(a, b) {
    const y = Math.sin(toRad(b.lng - a.lng)) * Math.cos(toRad(b.lat));
    const x = Math.cos(toRad(a.lat)) * Math.sin(toRad(b.lat)) - Math.sin(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.cos(toRad(b.lng - a.lng));

    return (toDeg(Math.atan2(y, x)) + 360) % 360;
}

/** Decodes a Google encoded polyline into [{lat, lng}]. Throws on malformed input. */
export function decodePolyline(encoded, precision = 5) {
    const factor = 10 ** precision;
    const points = [];
    let index = 0;
    let lat = 0;
    let lng = 0;

    const next = () => {
        let result = 0;
        let shift = 0;
        let byte;

        do {
            if (index >= encoded.length) {
                throw new Error('Truncated polyline');
            }

            byte = encoded.charCodeAt(index++) - 63;

            if (byte < 0 || byte > 63) {
                throw new Error('Invalid polyline character');
            }

            result |= (byte & 0x1f) << shift;
            shift += 5;
        } while (byte >= 0x20);

        return result & 1 ? ~(result >> 1) : result >> 1;
    };

    while (index < encoded.length) {
        lat += next();
        lng += next();
        points.push({ lat: lat / factor, lng: lng / factor });
    }

    return points;
}

/** Encodes [{lat, lng}] as a Google encoded polyline. */
export function encodePolyline(points, precision = 5) {
    const factor = 10 ** precision;
    let output = '';
    let prevLat = 0;
    let prevLng = 0;

    const encodeValue = (value) => {
        let v = value < 0 ? ~(value << 1) : value << 1;
        let chunk = '';

        while (v >= 0x20) {
            chunk += String.fromCharCode((0x20 | (v & 0x1f)) + 63);
            v >>= 5;
        }

        return chunk + String.fromCharCode(v + 63);
    };

    for (const point of points) {
        const lat = Math.round(point.lat * factor);
        const lng = Math.round(point.lng * factor);
        output += encodeValue(lat - prevLat) + encodeValue(lng - prevLng);
        prevLat = lat;
        prevLng = lng;
    }

    return output;
}

/** Linear interpolation between two points (fine at the few-hundred-metre steps a moving car makes). */
export function interpolate(a, b, t) {
    const k = Math.max(0, Math.min(1, t));

    return { lat: a.lat + (b.lat - a.lat) * k, lng: a.lng + (b.lng - a.lng) * k };
}

/** Shortest-way interpolation between two headings in degrees. */
export function interpolateHeading(from, to, t) {
    const delta = ((((to - from) % 360) + 540) % 360) - 180;

    return (from + delta * Math.max(0, Math.min(1, t)) + 360) % 360;
}

/** Ease-out (exponential feel, no overshoot) for marker movement. */
export function easeOut(t) {
    return 1 - (1 - Math.max(0, Math.min(1, t))) ** 3;
}

/**
 * The point at a distance along a path, with the heading of the segment it lies on.
 * Used by the demo's simulated driver and by any caller that animates along a route.
 */
export function pointAlong(path, metres) {
    if (path.length === 0) {
        return null;
    }

    let remaining = Math.max(0, metres);

    for (let i = 1; i < path.length; i++) {
        const length = haversine(path[i - 1], path[i]);

        if (remaining <= length || i === path.length - 1) {
            const t = length > 0 ? Math.min(1, remaining / length) : 1;

            return { ...interpolate(path[i - 1], path[i], t), heading: bearing(path[i - 1], path[i]) };
        }

        remaining -= length;
    }

    return { ...path[0], heading: 0 };
}

/** Total length of a path in metres. */
export function pathLength(path) {
    let total = 0;

    for (let i = 1; i < path.length; i++) {
        total += haversine(path[i - 1], path[i]);
    }

    return total;
}

/** [[south, west], [north, east]] around every given point, or null when there are none. */
export function boundsOf(points) {
    const valid = points.filter((p) => p && Number.isFinite(p.lat) && Number.isFinite(p.lng));

    if (valid.length === 0) {
        return null;
    }

    const lats = valid.map((p) => p.lat);
    const lngs = valid.map((p) => p.lng);

    return [
        [Math.min(...lats), Math.min(...lngs)],
        [Math.max(...lats), Math.max(...lngs)],
    ];
}

/** "4.2 km" / "850 m" for distances, "12 min" / "1 h 05 min" for durations. */
export function formatDistance(metres) {
    if (!Number.isFinite(metres)) {
        return '';
    }

    return metres < 1000 ? `${Math.round(metres / 10) * 10} m` : `${(metres / 1000).toFixed(metres < 10000 ? 1 : 0)} km`;
}

export function formatDuration(seconds) {
    if (!Number.isFinite(seconds)) {
        return '';
    }

    const minutes = Math.max(1, Math.round(seconds / 60));

    if (minutes < 60) {
        return `${minutes} min`;
    }

    return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')} min`;
}
