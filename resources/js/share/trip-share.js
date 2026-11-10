/**
 * Public trip-share page (/t/{token}), R-46 ppass D-2011. Polls the status endpoint without credentials (the page
 * has no cookie to send), writes what the server says, and moves the car on the map. Every time string arrives
 * ready to read from the server, so nothing here formats a time or a place.
 */
import { mountMap, loadGeoConfig } from '../geo/index.js';
import { positionNote, shouldReload } from './state.js';

const POLL_MS = 5000;
const root = document.querySelector('[data-share]');
const frame = document.querySelector('[data-eh-map]');
const first = JSON.parse(document.querySelector('script[data-share-state]')?.textContent || 'null');

let map = null;
let touched = false;
let lastSpot = '';
let timer = null;

const field = (name) => root.querySelector(`[data-field="${name}"]`);

function text(name, value) {
    const el = field(name);
    if (el && el.textContent !== value) el.textContent = value;
    return el;
}

function showCar(payload) {
    if (!map) return;
    const spot = payload.approximate_position;
    map.setDriver(spot ? { lat: spot.lat, lng: spot.lng } : null);
    const key = spot ? `${spot.lat},${spot.lng}` : '';
    // Keep the car in view until the reader moves the map themselves.
    if (key !== lastSpot && !touched) map.fitToContent({ maxZoom: 15 });
    lastSpot = key;
}

function apply(payload) {
    text('state_label', payload.state_label);

    const eta = field('eta');
    eta.hidden = !payload.eta_text;
    text('eta', payload.eta_text || '');

    field('route').hidden = !payload.from_area && !payload.to_area;
    text('from_area', payload.from_area || 'Area not given');
    text('to_area', payload.to_area || 'Area not given');

    const plate = field('registration');
    plate.hidden = !payload.vehicle;
    text('registration', payload.vehicle ? payload.vehicle.registration : '');
    text('vehicle', payload.vehicle ? payload.vehicle.description : 'No car assigned yet');
    text('driver', payload.driver_first_name || 'not assigned yet');

    text('position', positionNote(payload));
    text('checked', payload.checked_time);
    text('expires', payload.expires_time);
    text('stale', '');
    showCar(payload);
}

async function tick() {
    try {
        const response = await fetch(root.dataset.statusUrl, { credentials: 'omit', cache: 'no-store', headers: { Accept: 'application/json' } });

        if (response.status === 404) {
            // Revoked, expired or ended: the server's own page says so, with the emergency numbers.
            clearInterval(timer);
            window.location.reload();
            return;
        }

        if (!response.ok) throw new Error(`status ${response.status}`);

        const payload = await response.json();

        if (shouldReload(first, payload)) {
            // The trip ended or its route changed: the server renders that page (no map, or the new line).
            clearInterval(timer);
            window.location.reload();
            return;
        }

        apply(payload);
    } catch {
        text('stale', 'Could not refresh. Showing the last known state; still trying.');
    }
}

async function startMap() {
    if (!frame) return;

    try {
        const config = await loadGeoConfig();
        const spot = first?.approximate_position;
        map = await mountMap(frame.querySelector('[data-eh-map-canvas]'), { config, center: spot ? { lat: spot.lat, lng: spot.lng } : undefined, zoom: spot ? 14 : undefined });
        frame.dataset.mapState = 'ready';
        if (root.dataset.route) map.drawRoute(root.dataset.route);
        if (first) showCar(first);
        if (!first?.approximate_position) map.fitToContent({ maxZoom: 15 });
        for (const type of ['pointerdown', 'wheel', 'keydown']) frame.addEventListener(type, () => { touched = true; }, { passive: true });
    } catch {
        frame.dataset.mapState = 'unavailable';
        const message = frame.querySelector('[data-eh-map-message] span');
        if (message) message.textContent = 'The map could not load on this connection. The trip details below are still live.';
    }
}

if (root && first && window.fetch) {
    timer = setInterval(tick, POLL_MS);
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && tick());
    window.addEventListener('pagehide', () => clearInterval(timer));
    startMap();
}
