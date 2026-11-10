// Small geometry and formatting helpers for the driver app (no map engine needed).

const R = 6371008.8;

/** Great-circle distance in metres. */
export function haversine(a, b) {
    const toRad = (d) => (d * Math.PI) / 180;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;

    return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
}

export function formatDistance(m) {
    if (m === null || m === undefined || !Number.isFinite(m)) return null;
    if (m < 1000) return `${Math.max(10, Math.round(m / 10) * 10)} m`;

    return `${(m / 1000).toFixed(m < 10000 ? 1 : 0)} km`;
}

export function formatDuration(s) {
    if (s === null || s === undefined || !Number.isFinite(s)) return null;
    const min = Math.max(1, Math.round(s / 60));
    if (min < 60) return `${min} min`;

    return `${Math.floor(min / 60)} h ${min % 60} min`;
}

/** "4:05" for a running timer. */
export function formatClock(totalSeconds) {
    const s = Math.max(0, Math.floor(totalSeconds));

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/**
 * The area of an address for the offer card before acceptance: the last two comma-separated parts (suburb and city
 * in South African addresses), without a postcode or the country.
 */
export function destinationArea(address) {
    const parts = String(address ?? '')
        .split(',')
        .map((p) => p.trim())
        .filter((p) => p !== '' && !/^\d{4}$/.test(p) && !/^south africa$/i.test(p) && !PROVINCES.test(p));
    if (parts.length <= 1) return parts[0] ?? '';

    return parts.slice(-2).join(', ');
}

const PROVINCES = /^(gauteng|western cape|eastern cape|northern cape|kwazulu-natal|kwazulu natal|free state|limpopo|mpumalanga|north west)$/i;
const STREETISH = /\d|\b(road|rd|street|str|st|avenue|ave|drive|dr|lane|ln|boulevard|blvd|way|crescent|cres|close|highway|hwy|place|pl|square|sq|terrace|circle|loop|parkway|freeway|mall)\b/i;

/**
 * An address as a driver reads it at a glance (D-2102): the street (or the place and its street) and the suburb,
 * without the city, postcode, province or country. "Oxford Road, Rosebank, Johannesburg, 2196, South Africa" becomes
 * "Oxford Road, Rosebank". The full address stays one tap away.
 */
export function shortAddress(address) {
    const parts = String(address ?? '')
        .split(',')
        .map((p) => p.trim())
        .filter((p) => p !== '' && !/^\d{4}$/.test(p) && !/^south africa$/i.test(p) && !PROVINCES.test(p));
    if (parts.length <= 2) return parts.join(', ');
    const suburb = parts[parts.length - 2];
    const out = [parts[0]];
    if (!STREETISH.test(parts[0]) && parts.length >= 4 && STREETISH.test(parts[1])) out.push(parts[1]);
    if (!out.includes(suburb)) out.push(suburb);

    return out.join(', ');
}

/** Money from integer minor units, "R1 250.50" (narrow no-break space for thousands), minus as U+2212. */
export function formatMoney(minor, currency = 'ZAR') {
    if (minor === null || minor === undefined || !Number.isFinite(Number(minor))) return null;
    const n = Number(minor);
    const abs = Math.abs(n);
    const whole = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    const cents = String(abs % 100).padStart(2, '0');
    const symbol = currency === 'ZAR' ? 'R' : `${currency} `;

    return `${n < 0 ? '−' : ''}${symbol}${whole}.${cents}`;
}
