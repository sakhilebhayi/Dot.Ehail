// Small trip maps (R-46 polish, D-2203): a frame [data-eh-stopmap] carrying JSON in data-map
// ({ pickup, dropoff, sos: {lat, lng, accuracy}, driver, trail: [{lat, lng}] }) is mounted with the geo map when it
// scrolls near the screen, so a list of approvals does not load a map per row up front. The words on the page say
// the same places; the map only shows where they are.
import { mountMap } from '../geo/index.js';

const LABELS = { pickup: 'Pickup', dropoff: 'Drop-off' };

async function draw(frame) {
    const status = frame.querySelector('[data-eh-stopmap-status]');
    let data;
    try { data = JSON.parse(frame.dataset.map || '{}'); } catch { data = {}; }
    let map;
    try {
        map = await mountMap(frame.querySelector('[data-eh-map-canvas]'));
    } catch {
        if (status) status.textContent = 'The map could not load. The places are written out beside it.';
        return;
    }
    if (data.pickup) map.setPickup(data.pickup, LABELS.pickup);
    if (data.dropoff) map.setDestination(data.dropoff, LABELS.dropoff);
    const points = [data.pickup, data.dropoff, data.sos, data.driver].filter(Boolean);

    if (data.trail?.length || data.sos || data.driver) {
        const L = (await import('leaflet')).default;
        const css = getComputedStyle(frame);
        const ink = css.getPropertyValue('--eh-ink-2').trim() || '#3a4a43';
        const stop = css.getPropertyValue('--eh-stop').trim() || '#b3261e';
        if (data.trail?.length > 1) {
            L.polyline(data.trail.map((p) => [p.lat, p.lng]), { color: ink, weight: 3, dashArray: '2 6', lineCap: 'round', interactive: false }).addTo(map.map);
            points.push(...data.trail);
        }
        if (data.driver) map.setDriver({ lat: data.driver.lat, lng: data.driver.lng });
        if (data.sos) {
            if (data.sos.accuracy) L.circle([data.sos.lat, data.sos.lng], { radius: Math.max(5, data.sos.accuracy), color: stop, weight: 1, fillColor: stop, fillOpacity: 0.12, interactive: false }).addTo(map.map);
            L.marker([data.sos.lat, data.sos.lng], {
                icon: L.divIcon({ className: 'ops-marker-wrap', html: '<span class="ops-marker ops-marker--sos" aria-hidden="true"></span>', iconSize: [22, 22], iconAnchor: [11, 11] }),
                keyboard: false, interactive: false, zIndexOffset: 800,
            }).addTo(map.map);
        }
    }

    const valid = points.filter((p) => Number.isFinite(p?.lat) && Number.isFinite(p?.lng));
    // A small map is a picture of where, not a place to pan around: no wheel zoom stealing the page's scroll.
    if (!frame.classList.contains('eh-stopmap--tall')) {
        map.map.scrollWheelZoom.disable();
        // The credit stays; the "report a map issue" link does not fit a 15rem frame and stays on the full maps.
        const report = [...frame.querySelectorAll('.leaflet-control-attribution a')].find((a) => /report a map issue/i.test(a.textContent));
        if (report) {
            const bar = report.previousSibling;
            if (bar?.nodeType === Node.TEXT_NODE) bar.textContent = bar.textContent.replace(/\s*\|\s*$/, '');
            report.remove();
        }
    }
    const fit = () => {
        map.invalidateSize();
        if (valid.length === 1) map.map.setView([valid[0].lat, valid[0].lng], 15, { animate: false });
        else if (valid.length > 1) map.map.fitBounds(L_bounds(valid), { padding: [28, 28], maxZoom: 15, animate: false });
    };
    fit();
    // The geo map settles its own first view after mounting; fit again once it has.
    requestAnimationFrame(() => requestAnimationFrame(fit));
    setTimeout(fit, 400);
    if (status && !valid.length) status.textContent = 'No position was recorded.';
}

function L_bounds(points) {
    const lats = points.map((p) => p.lat);
    const lngs = points.map((p) => p.lng);
    return [[Math.min(...lats), Math.min(...lngs)], [Math.max(...lats), Math.max(...lngs)]];
}

export function initStopMaps(root = document) {
    const frames = [...root.querySelectorAll('[data-eh-stopmap]:not([data-eh-stopmap-ready])')];
    if (!frames.length) return;
    const start = (frame) => { frame.setAttribute('data-eh-stopmap-ready', ''); draw(frame); };
    if (!('IntersectionObserver' in window)) { frames.forEach(start); return; }
    const seen = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            seen.unobserve(entry.target);
            start(entry.target);
        });
    }, { rootMargin: '200px 0px' });
    frames.forEach((frame) => seen.observe(frame));
}
