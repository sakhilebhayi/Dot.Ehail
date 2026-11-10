// Navigation hand-off (architecture section 7): Dot.Ehail shows the stop and the distance; the phone's own maps
// app does turn-by-turn. Each provider's documented universal link, carrying the next stop only.

export const NAV_APPS = { google: 'Google Maps', waze: 'Waze', apple: 'Apple Maps' };

export function navLink(app, lat, lng) {
    if (lat === null || lng === null || lat === undefined || lng === undefined || lat === '' || lng === '') return null;
    if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) return null;
    const p = `${Number(lat).toFixed(6)},${Number(lng).toFixed(6)}`;

    switch (app) {
        case 'waze':
            return `https://waze.com/ul?ll=${p}&navigate=yes`;
        case 'apple':
            return `https://maps.apple.com/?daddr=${p}&dirflg=d`;
        default:
            return `https://www.google.com/maps/dir/?api=1&destination=${p}&travelmode=driving`;
    }
}

// Per-phone preferences (navigation app, offer sound, vibration). They belong to the device, so they live in this
// browser's storage; a failure to store falls back to the defaults.
const PREFS = 'eh-drive-prefs';
const DEFAULTS = { nav: 'google', sound: true, vibrate: true };

export function readPrefs(store = globalThis.localStorage) {
    try {
        return { ...DEFAULTS, ...(JSON.parse(store?.getItem(PREFS) ?? '{}') ?? {}) };
    } catch {
        return { ...DEFAULTS };
    }
}

export function writePrefs(prefs, store = globalThis.localStorage) {
    try {
        store?.setItem(PREFS, JSON.stringify({ ...readPrefs(store), ...prefs }));

        return true;
    } catch {
        return false;
    }
}
