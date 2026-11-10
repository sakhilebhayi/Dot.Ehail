// Dot.Ehail geo module entry. Small on purpose: the map engine (Leaflet + its CSS) loads only when mountMap()
// is called, as a separate chunk, so pages that show a map pay for it after their first view.

import '../../css/geo/geo.css';
import { getJson } from './network.js';

export { createPlaceSearch } from './search.js';
export { bindLocateButton, locateOnce, locationPermission } from './locate.js';
export { decodePolyline, encodePolyline, formatDistance, formatDuration, haversine, pointAlong, pathLength } from './geometry.js';
export { getJson, GeoRequestError } from './network.js';

let configPromise = null;

/** The /geo/config payload, from an inline JSON script (data-geo-config) when the page has one, else fetched. */
export function loadGeoConfig(url = '/geo/config') {
    if (!configPromise) {
        const inline = document.querySelector('script[data-geo-config]');
        configPromise = inline ? Promise.resolve(JSON.parse(inline.textContent)) : getJson(url);
    }

    return configPromise;
}

/**
 * mountMap(el, {style, center, zoom, onMapClick}) -> Promise<controller>. style: 'default' | 'night' adds a class
 * hook for the --eh-map-* tile filter; by default the theme follows the page.
 */
export async function mountMap(el, { style, center, zoom, onMapClick, config } = {}) {
    el.classList.add('eh-map', 'is-loading');
    el.setAttribute('aria-busy', 'true');

    try {
        const [resolvedConfig, { createMap }] = await Promise.all([config ? Promise.resolve(config) : loadGeoConfig(), import('./map.js')]);
        const controller = createMap(el, resolvedConfig, { center, zoom, onMapClick });

        if (style) {
            el.dataset.mapStyle = style;
        }

        return controller;
    } catch (error) {
        el.classList.add('is-failed');
        el.textContent = 'The map could not load on this connection. Search and trip details still work.';
        throw error;
    } finally {
        el.classList.remove('is-loading');
        el.removeAttribute('aria-busy');
    }
}

// The development demo page wires itself up (its code is a separate lazy chunk).
if (typeof document !== 'undefined' && document.querySelector('[data-geo-demo]')) {
    import('./demo.js').then(({ startDemo }) => startDemo());
}
