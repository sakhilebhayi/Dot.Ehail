// Public fare calculator: two points (map taps, the device location, or address search when signed in), then the
// public estimate for every category with its full breakdown. Nothing here books or stores anything.

import { createPlaceSearch, loadGeoConfig, locateOnce, mountMap } from '../geo/index.js';
import { announce, api, island } from './api.js';
import { formatKm, formatMinutes, renderBreakdown } from './breakdown.js';
import { el, icon } from './dom.js';
import { categoryIcon, unavailableText } from './labels.js';

const data = island('pax-fares-data') || {};
const sheet = document.getElementById('pax-fares');
const frame = document.getElementById('pax-map');
const out = sheet?.querySelector('[data-pax-estimate]');
const state = { pickup: null, dropoff: null };
let config = null;
let map = null;
let searches = {};
let seq = 0;

function label(kind, text) {
    const node = sheet.querySelector(`[data-pax-calc="${kind}"]`);
    if (node) {
        node.textContent = text;
        node.dataset.empty = 'false';
    }
    searches[kind]?.setValue(text);
}

function set(kind, point, text) {
    state[kind] = point;
    label(kind, text);
    if (map) {
        kind === 'pickup' ? map.setPickup(point) : map.setDestination(point);
        fit();
    }
    if (state.pickup && state.dropoff) estimate();
}

/** Fit the chosen points into the part of the map the calculator panel leaves visible. */
function fit() {
    const points = [state.pickup, state.dropoff].filter(Boolean);
    if (!map || !points.length) return;
    const wide = matchMedia('(min-width: 1024px)').matches;
    const cover = wide ? 0 : Math.max(0, innerHeight - sheet.getBoundingClientRect().top);
    if (points.length === 1) {
        map.map.panTo([points[0].lat, points[0].lng]);
        return;
    }
    map.map.fitBounds(points.map((p) => [p.lat, p.lng]), { paddingTopLeft: [wide ? 448 : 32, 48], paddingBottomRight: [32, cover + 32], maxZoom: 15 });
}

async function estimate() {
    const mine = ++seq;
    out.setAttribute('aria-busy', 'true');
    out.replaceChildren(el('p', { class: 'pax-rule' }, 'Working out the fares for this route.'));
    try {
        const { data: result } = await api(data.urls.estimate, {
            method: 'POST',
            body: { pickup_lat: state.pickup.lat, pickup_lng: state.pickup.lng, dropoff_lat: state.dropoff.lat, dropoff_lng: state.dropoff.lng },
        });
        if (mine !== seq) return;
        draw(result);
    } catch (error) {
        if (mine === seq) out.replaceChildren(el('p', { class: 'pax-field__status', 'data-kind': 'error' }, error.message));
    } finally {
        out.removeAttribute('aria-busy');
    }
}

function draw(result) {
    if (result.available === false || !result.options?.length) {
        out.replaceChildren(el('div', { class: 'eh-banner eh-banner--caution', role: 'status' }, [icon('warning'), el('div', { class: 'eh-banner__body' }, [el('p', { class: 'eh-banner__title' }, 'No fare for this trip'), el('div', { class: 'eh-banner__text' }, result.available === false ? unavailableText(result, config?.routing?.unavailableMessage) : 'The calculator prices the ride types that have drivers near the pickup right now. For this pickup:'), result.available === false ? null : el('ul', { class: 'pax-unavailable' }, (result.unavailable || []).map((u) => el('li', {}, u.message)))])]));
        announce('No fare could be worked out for this trip.');
        return;
    }

    const route = result.route;
    const children = [
        el('p', { class: 'pax-trip' }, [el('span', { class: 'pax-trip__figure' }, formatKm(route.distance_m)), el('span', { class: 'pax-trip__figure' }, formatMinutes(route.duration_s)), el('span', { class: 'pax-trip__note' }, 'Estimated road distance and driving time.')]),
    ];
    if (result.options.some((o) => o.tariff?.is_example)) {
        children.push(el('p', { class: 'pax-note' }, [icon('info'), 'Example tariff: placeholder prices until real rates are published']));
    }

    for (const option of result.options) {
        const list = el('dl', { class: 'pax-fare' });
        const model = renderBreakdown(list, option.fare, { caption: 'Estimated total' });
        children.push(
            el('section', { class: 'pax-option', style: 'margin-bottom: var(--eh-space-2)', 'aria-label': option.category.name }, [
                el('h2', { class: 'pax-option__name', style: 'margin:0' }, [icon(categoryIcon(option.category.icon_key)), option.category.name]),
                el('span', { class: 'pax-option__total eh-num' }, model.total?.text || ''),
                el('p', { class: 'pax-option__meta', style: 'margin:0' }, [option.category.description, ` ${option.category.capacity} seats.`]),
                el('details', { class: 'pax-option__detail' }, [el('summary', { class: 'pax-option__more' }, 'Every line of this fare'), list, el('div', { class: 'pax-fare-notes' }, [...model.notes, option.wording].map((n) => el('p', {}, n)))]),
            ]),
        );
    }
    if (result.unavailable?.length) children.push(el('ul', { class: 'pax-unavailable' }, result.unavailable.map((u) => el('li', {}, [icon('info'), u.message]))));
    children.push(el('div', { class: 'pax-actions' }, [el('a', { class: 'eh-btn eh-btn--primary eh-btn--lg eh-btn--block', href: data.canSearch ? data.urls.book : data.urls.login }, el('span', { class: 'eh-btn__label' }, data.canSearch ? 'Book a ride' : 'Sign in to book')), el('p', { class: 'pax-rule' }, 'Estimates only. When you book, you get a quote held for five minutes that you accept before the ride is requested.')]));
    out.replaceChildren(...children);
    announce(`Fares ready for ${result.options.length} ride types.`);
}

function reset() {
    state.pickup = state.dropoff = null;
    seq++;
    map?.setPickup(null);
    map?.setDestination(null);
    for (const kind of ['pickup', 'dropoff']) {
        const node = sheet.querySelector(`[data-pax-calc="${kind}"]`);
        if (node) {
            node.dataset.empty = 'true';
            node.textContent = kind === 'pickup' ? 'Tap the map, or use your location' : 'Then tap where you are going';
        }
        searches[kind]?.setValue('');
    }
    out.replaceChildren(el('p', { class: 'pax-rule' }, 'Prices appear here once both points are set.'));
}

async function start() {
    if (!sheet) return;
    config = await loadGeoConfig();

    if (data.canSearch) {
        for (const kind of ['pickup', 'dropoff']) {
            const root = sheet.querySelector(`[data-pax-search="${kind}"]`);
            if (root) searches[kind] = createPlaceSearch(root, { config, near: () => state.pickup, onSelect: (place) => set(kind, { lat: place.lat, lng: place.lng }, place.label) });
        }
    }

    sheet.querySelector('[data-pax-reset]')?.addEventListener('click', reset);
    sheet.querySelector('[data-pax-locate]')?.addEventListener('click', async (event) => {
        const status = sheet.querySelector('[data-pax-locate-status]');
        const button = event.currentTarget;
        button.setAttribute('aria-busy', 'true');
        try {
            const fix = await locateOnce();
            status.textContent = 'Pickup set to your location. It is used for this estimate only and not stored.';
            set('pickup', { lat: fix.lat, lng: fix.lng }, 'Your location');
        } catch (error) {
            status.textContent = error.message;
        } finally {
            button.removeAttribute('aria-busy');
        }
    });

    try {
        map = await mountMap(frame.querySelector('[data-eh-map-canvas]'), {
            config,
            onMapClick: (point) => (state.pickup && !state.dropoff ? set('dropoff', point, 'Point on the map') : !state.pickup ? set('pickup', point, 'Point on the map') : (reset(), set('pickup', point, 'Point on the map'))),
        });
        frame.dataset.mapState = 'ready';
    } catch {
        frame.dataset.mapState = 'unavailable';
    }
}

start();
