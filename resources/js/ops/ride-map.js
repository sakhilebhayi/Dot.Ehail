// One ride on the map: pickup and drop-off stops, the planned road route when one was attached, and (for roles that
// may see positions, while a driver holds the ride) the driver's latest fix from the geo API, refreshed every 10 s.
import { mountMap } from '../geo/index.js';
import { formatDuration } from './helpers.js';

export async function startRideMap(root) {
    const status = root.querySelector('[data-ops-map-status]');
    const say = (text) => { if (status) { status.hidden = !text; status.textContent = text || ''; } };
    let map;
    try {
        map = await mountMap(root.querySelector('[data-eh-map-canvas]'));
    } catch {
        say('The map could not load. The ride details are all on this page.');
        return;
    }

    const pickup = root.dataset.pickup ? JSON.parse(root.dataset.pickup) : null;
    const dropoff = root.dataset.dropoff ? JSON.parse(root.dataset.dropoff) : null;
    if (pickup) map.setPickup(pickup, 'Pickup');
    if (dropoff) map.setDestination(dropoff, 'Drop-off');
    if (root.dataset.polyline) map.drawRoute(root.dataset.polyline);
    map.fitToContent({ maxZoom: 15 });
    say(root.dataset.polyline ? '' : 'No planned road route is attached to this ride; only the stops are drawn.');

    const url = root.dataset.driverUrl;
    if (!url) return;
    const refresh = async () => {
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) return;
            const body = await response.json();
            const fix = body.driver_location;
            if (fix) {
                map.setDriver({ lat: fix.lat, lng: fix.lng, heading: fix.heading_deg });
                if (fix.age_seconds > 60) say(`Driver's last position is ${formatDuration(fix.age_seconds)} old.`);
            } else {
                say('The driver has not reported a position yet.');
            }
        } catch { /* keep the last drawn state */ }
        setTimeout(refresh, document.hidden ? 30000 : 10000);
    };
    refresh();
}
