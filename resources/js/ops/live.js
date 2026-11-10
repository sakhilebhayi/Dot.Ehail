// Live board: polls /ops/live/feed (ETag, 5 s while the tab is visible, 30 s when hidden), draws online drivers
// and open rides on the geo map, and refreshes the figures and queues. Driver positions arrive only for roles that
// may see them; otherwise the map shows rides alone and says so.
import { mountMap } from '../geo/index.js';
import { formatDuration } from './helpers.js';

const VISIBLE_MS = 5000;
const HIDDEN_MS = 30000;

function markerIcon(L, kind, label) {
    return L.divIcon({ className: 'ops-marker-wrap', html: `<span class="ops-marker ops-marker--${kind}" aria-hidden="true"></span>`, iconSize: [16, 16], iconAnchor: [8, 8], tooltipAnchor: [0, -10], title: label });
}

function setText(root, selector, value) {
    root.querySelectorAll(selector).forEach((el) => { el.textContent = value; });
}

function renderQueue(list, items, emptyText) {
    if (!list) return;
    list.replaceChildren();
    if (!items.length) {
        const li = document.createElement('li');
        const p = document.createElement('p');
        p.className = 'ops-none';
        p.style.padding = '0';
        p.textContent = emptyText;
        li.appendChild(p);
        list.appendChild(li);
        return;
    }
    items.forEach((item) => {
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.className = 'ops-queue__title';
        a.href = item.href;
        a.textContent = item.title;
        const meta = document.createElement('span');
        meta.className = 'ops-queue__meta';
        meta.textContent = item.meta;
        const trail = document.createElement('span');
        trail.className = 'ops-queue__trail';
        const timer = document.createElement('span');
        timer.className = 'ops-timer';
        timer.dataset.tone = item.tone || 'calm';
        timer.textContent = item.timer || '';
        trail.appendChild(timer);
        li.append(a, meta, trail);
        list.appendChild(li);
    });
}

export async function startLive(root) {
    const feedUrl = root.dataset.feed;
    const status = root.querySelector('[data-ops-map-status]');
    const canvas = root.querySelector('[data-eh-map-canvas]');
    let map = null;
    let L = null;
    const layers = new Map();
    let etag = null;
    let fitted = false;

    try {
        [map, L] = await Promise.all([mountMap(canvas), import('leaflet')]);
        root.querySelector('[data-eh-map]')?.setAttribute('data-map-state', 'ready');
    } catch {
        if (status) { status.hidden = false; status.textContent = 'The map could not load. The queues and figures below still update.'; }
    }

    const draw = (payload) => {
        if (!map || !L) return;
        const seen = new Set();
        const place = (key, lat, lng, kind, label, href) => {
            seen.add(key);
            let marker = layers.get(key);
            if (!marker) {
                marker = L.marker([lat, lng], { icon: markerIcon(L, kind, label), keyboard: false, riseOnHover: true }).addTo(map.map);
                marker.bindTooltip(label, { direction: 'top' });
                if (href) marker.on('click', () => window.location.assign(href));
                layers.set(key, marker);
            } else {
                marker.setLatLng([lat, lng]);
                marker.setIcon(markerIcon(L, kind, label));
                marker.setTooltipContent(label);
            }
        };
        (payload.drivers || []).forEach((d) => {
            if (d.lat === null || d.lng === null) return;
            const kind = d.stale ? 'stale' : (d.ride_id ? 'busy' : 'driver');
            place(`d${d.id}`, d.lat, d.lng, kind, `${d.label}: ${d.state_label}${d.stale ? ', no signal for ' + formatDuration(d.age_s) : ''}`, d.href);
        });
        (payload.rides || []).forEach((r) => {
            if (r.pickup === null) return;
            const kind = r.status === 'needs_review' ? 'review' : 'pickup';
            place(`r${r.id}`, r.pickup.lat, r.pickup.lng, kind, `Ride ${r.id}: ${r.status_label}`, r.href);
        });
        layers.forEach((marker, key) => {
            if (!seen.has(key)) { marker.remove(); layers.delete(key); }
        });
        if (!fitted && layers.size) {
            const bounds = L.latLngBounds([...layers.values()].map((m) => m.getLatLng()));
            map.map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 });
            fitted = true;
        }
        if (status) {
            const message = payload.positions_allowed ? (layers.size ? '' : 'No online driver or open ride has a position right now.') : 'Driver positions are not part of your role; open rides are shown.';
            status.hidden = !message;
            status.textContent = message;
        }
    };

    const apply = (payload) => {
        Object.entries(payload.counts || {}).forEach(([key, value]) => setText(root, `[data-live-count="${key}"]`, String(value)));
        setText(root, '[data-live-stamp]', `Updated ${new Date(payload.generated_at).toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}`);
        Object.entries(payload.queues || {}).forEach(([key, items]) => {
            const list = root.querySelector(`[data-live-queue="${key}"]`);
            renderQueue(list, items, list?.dataset.empty || 'Nothing here.');
        });
        draw(payload);
    };

    const poll = async () => {
        try {
            const response = await fetch(feedUrl, { headers: { Accept: 'application/json', ...(etag ? { 'If-None-Match': etag } : {}) }, credentials: 'same-origin' });
            if (response.status === 200) {
                etag = response.headers.get('ETag');
                apply(await response.json());
            } else if (response.status === 403 || response.status === 401) {
                setText(root, '[data-live-stamp]', 'Updates stopped: sign in again.');
                return;
            }
        } catch {
            setText(root, '[data-live-stamp]', 'Connection lost. Retrying.');
        }
        setTimeout(poll, document.hidden ? HIDDEN_MS : VISIBLE_MS);
    };

    const initial = root.querySelector('script[data-live-initial]');
    if (initial) apply(JSON.parse(initial.textContent));
    setTimeout(poll, VISIBLE_MS);
}
