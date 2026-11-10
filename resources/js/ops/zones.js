// Service areas and zones on the map: every shape drawn from the page's JSON; an editor that adds corners on map
// clicks, drags corners, removes one on double-click, and saves the polygon as JSON to the ops zone endpoints.
import { mountMap } from '../geo/index.js';
import { errorMessage } from './helpers.js';

const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

export async function startZones(root) {
    const data = JSON.parse(root.querySelector('script[data-zones-initial]').textContent);
    const status = root.querySelector('[data-ops-map-status]');
    let map;
    let L;
    try {
        [map, L] = await Promise.all([mountMap(root.querySelector('[data-eh-map-canvas]')), import('leaflet')]);
        status.hidden = true;
    } catch {
        status.textContent = 'The map could not load, so shapes cannot be drawn here. Switching areas on and off still works.';
        return;
    }

    const layers = new Map();
    const styleFor = (kind, active) => kind === 'area'
        ? { color: active ? css('--eh-accent') : '#8a9690', weight: 2, fillOpacity: active ? 0.08 : 0.03, dashArray: active ? null : '4 4' }
        : { color: active ? '#b8860b' : '#8a9690', weight: 2, fillColor: css('--eh-signal') || '#f2c230', fillOpacity: active ? 0.18 : 0.05, dashArray: active ? null : '4 4' };

    data.areas.forEach((a) => layers.set(`area-${a.id}`, { shape: a, kind: 'area', layer: L.polygon(a.polygon, styleFor('area', a.active)).bindTooltip(a.name).addTo(map.map) }));
    data.zones.forEach((z) => layers.set(`zone-${z.id}`, { shape: z, kind: 'zone', layer: L.polygon(z.polygon, styleFor('zone', z.active)).bindTooltip(`${z.name} (${z.kind})`).addTo(map.map) }));
    if (layers.size) {
        map.map.fitBounds(L.featureGroup([...layers.values()].map((x) => x.layer)).getBounds(), { padding: [24, 24] });
    } else {
        status.hidden = false;
        status.textContent = 'No shape yet.';
    }

    root.querySelectorAll('[data-zone-show]').forEach((btn) => btn.addEventListener('click', () => {
        const entry = layers.get(btn.dataset.zoneShow);
        if (entry) map.map.fitBounds(entry.layer.getBounds(), { padding: [32, 32] });
    }));

    if (root.dataset.canEdit !== '1') return;

    const ui = {
        hint: root.querySelector('[data-zone-hint]'), error: root.querySelector('[data-zone-error]'),
        nameWrap: root.querySelector('[data-zone-name-wrap]'), name: root.querySelector('[data-zone-name]'),
        kindWrap: root.querySelector('[data-zone-kind-wrap]'), kind: root.querySelector('[data-zone-kind]'),
        feeWrap: root.querySelector('[data-zone-fee-wrap]'), fee: root.querySelector('[data-zone-fee]'),
        exactWrap: root.querySelector('[data-zone-exact-wrap]'), exact: root.querySelector('[data-zone-exact]'),
        save: root.querySelector('[data-zone-save]'), cancel: root.querySelector('[data-zone-cancel]'),
    };
    let session = null; // { mode: 'edit'|'new-area'|'new-zone', url, method, points: LatLng[], markers, line, entry }

    const vertexIcon = L.divIcon({ className: 'ops-vertex-wrap', html: '<span class="ops-vertex" aria-hidden="true"></span>', iconSize: [14, 14], iconAnchor: [7, 7] });
    const redraw = () => {
        session.line?.remove();
        session.line = L.polygon(session.points, { color: css('--eh-ink'), weight: 2, dashArray: '6 4', fillOpacity: 0.06 }).addTo(map.map);
        session.markers.forEach((m) => m.remove());
        session.markers = session.points.map((p, i) => {
            const m = L.marker(p, { icon: vertexIcon, draggable: true, keyboard: false }).addTo(map.map);
            m.on('drag', (e) => { session.points[i] = e.target.getLatLng(); session.line.setLatLngs(session.points); });
            m.on('dblclick', (e) => { L.DomEvent.stop(e); session.points.splice(i, 1); redraw(); });
            return m;
        });
        ui.hint.textContent = `${session.points.length} corners. ${session.points.length < 3 ? 'A shape needs at least three.' : 'Save when it is right.'}`;
    };
    const show = (el, on) => { el.hidden = !on; };
    const start = (mode, opts) => {
        stop();
        session = { mode, markers: [], line: null, points: [], ...opts };
        if (opts.entry) { session.points = opts.entry.layer.getLatLngs()[0].map((p) => L.latLng(p.lat, p.lng)); opts.entry.layer.setStyle({ opacity: 0.2, fillOpacity: 0.02 }); }
        show(ui.nameWrap, mode !== 'edit'); show(ui.kindWrap, mode === 'new-zone'); show(ui.feeWrap, mode === 'new-zone');
        show(ui.exactWrap, mode === 'edit' && opts.entry?.kind === 'area'); show(ui.save, true); show(ui.cancel, true); show(ui.error, false);
        ui.name.value = ''; ui.fee.value = ''; ui.exact.checked = false;
        redraw();
        (mode === 'edit' ? ui.save : ui.name).focus();
    };
    function stop() {
        if (!session) return;
        session.markers.forEach((m) => m.remove());
        session.line?.remove();
        if (session.entry) session.entry.layer.setStyle(styleFor(session.entry.kind, session.entry.shape.active));
        session = null;
        [ui.nameWrap, ui.kindWrap, ui.feeWrap, ui.exactWrap, ui.save, ui.cancel, ui.error].forEach((el) => show(el, false));
        ui.hint.textContent = 'Choose a shape to edit, or start a new one.';
    }

    map.map.on('click', (e) => { if (session) { session.points.push(e.latlng); redraw(); } });
    root.querySelector('[data-zone-new-area]')?.addEventListener('click', () => start('new-area', { url: root.dataset.storeArea, method: 'POST' }));
    root.querySelectorAll('[data-zone-new-zone]').forEach((btn) => btn.addEventListener('click', () => {
        const area = data.areas.find((a) => String(a.id) === btn.dataset.zoneNewZone);
        start('new-zone', { url: area.zones_url, method: 'POST' });
        map.map.fitBounds(layers.get(`area-${area.id}`).layer.getBounds());
    }));
    root.querySelectorAll('[data-zone-edit]').forEach((btn) => btn.addEventListener('click', () => {
        const entry = layers.get(btn.dataset.zoneEdit);
        start('edit', { url: entry.shape.url, method: 'PATCH', entry });
        map.map.fitBounds(entry.layer.getBounds(), { padding: [32, 32] });
    }));
    ui.cancel.addEventListener('click', stop);
    ui.save.addEventListener('click', async () => {
        if (!session) return;
        const body = { polygon: session.points.map((p) => [Number(p.lat.toFixed(6)), Number(p.lng.toFixed(6))]) };
        if (session.mode !== 'edit') body.name = ui.name.value.trim();
        if (session.mode === 'new-zone') { body.kind = ui.kind.value; if (ui.fee.value.trim()) body.fee_code = ui.fee.value.trim(); }
        if (session.mode === 'edit' && ui.exact.checked) body.confirm_exact = true;
        ui.save.disabled = true;
        try {
            const response = await fetch(session.url, {
                method: session.method, credentials: 'same-origin', body: JSON.stringify(body),
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            });
            const json = await response.json().catch(() => null);
            if (response.ok) {
                try { sessionStorage.setItem('ops-toast', session.mode === 'edit' ? 'Shape saved.' : 'Created. It starts switched off: switch it on when ready.'); } catch { /* private mode */ }
                window.location.reload();
                return;
            }
            ui.error.textContent = errorMessage(response.status, json);
            show(ui.error, true);
        } catch {
            ui.error.textContent = 'No connection. Nothing was saved.';
            show(ui.error, true);
        } finally {
            ui.save.disabled = false;
        }
    });
}
