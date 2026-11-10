// Pure helper, kept out of map.js (which loads Leaflet and its stylesheet) so it can be tested in node.

/**
 * Reduced motion for every caller (R-46 a11y, D-1705): Leaflet animates panTo, setView and fitBounds unless each
 * call says otherwise, and callers outside geo (booking, trip, fares, ops) do not all say it. On this map every
 * move lands at once instead.
 */
export function stillMap(map) {
    for (const name of ['setView', 'panTo', 'fitBounds', 'panBy', 'setZoom']) {
        const original = map[name].bind(map);
        const at = name === 'setView' ? 2 : 1;
        map[name] = (...args) => {
            args[at] = { ...(args[at] || {}), animate: false };

            return original(...args);
        };
    }
    map.flyTo = (center, zoom) => map.setView(center, zoom);
    map.flyToBounds = (bounds, options) => map.fitBounds(bounds, options);

    return map;
}
