// Development demo of the geo module (served only when APP_ENV=local): search, use my location, tap to place,
// road route from the configured provider (or its honest unavailable message), and a SIMULATED driver.

import { bindLocateButton, createPlaceSearch, formatDistance, formatDuration, getJson, loadGeoConfig, mountMap, pathLength, pointAlong } from './index.js';

export async function startDemo() {
    const root = document.querySelector('[data-geo-demo]');
    const config = await loadGeoConfig();
    const summary = root.querySelector('[data-route-summary]');
    const simulateButton = root.querySelector('[data-simulate]');
    const followToggle = root.querySelector('[data-follow]');
    const locateStatus = root.querySelector('[data-locate-status]');
    const points = { pickup: null, destination: null };
    let routePath = [];
    let simulation = null;

    const setSummary = (text, kind = '') => {
        summary.textContent = text;
        summary.dataset.kind = kind;
    };

    const map = await mountMap(root.querySelector('[data-map]'), {
        config,
        onMapClick: async (point) => {
            const which = points.pickup ? 'destination' : 'pickup';
            await place(which, { ...point, label: `${point.lat.toFixed(5)}, ${point.lng.toFixed(5)}` });

            try {
                const body = await getJson(config.endpoints.reverse, point);

                if (body.result) {
                    searches[which].setValue(body.result.label);
                }
            } catch (error) {
                searches[which].setValue(`${point.lat.toFixed(5)}, ${point.lng.toFixed(5)}`);
            }
        },
    });

    const place = async (which, point) => {
        points[which] = point;
        which === 'pickup' ? map.setPickup(point, point.label) : map.setDestination(point, point.label);
        map.fitToContent();
        await route();
    };

    const route = async () => {
        map.drawRoute(null);
        routePath = [];
        simulateButton.disabled = !(points.pickup && points.destination);

        if (!points.pickup || !points.destination) {
            setSummary('Choose a pickup and a destination, or tap the map twice.');

            return;
        }

        if (!config.routing.configured) {
            setSummary(config.routing.unavailableMessage, 'error');

            return;
        }

        setSummary('Finding the road route…');

        try {
            const body = await getJson(config.endpoints.route, {
                from_lat: points.pickup.lat,
                from_lng: points.pickup.lng,
                to_lat: points.destination.lat,
                to_lng: points.destination.lng,
            });
            routePath = map.drawRoute(body.route.polyline);
            map.fitToContent();
            setSummary(`${formatDistance(body.route.distance_m)} by road, about ${formatDuration(body.route.duration_s)} (${body.route.provider}).`);
        } catch (error) {
            setSummary(error.message, 'error');
        }
    };

    const searches = {
        pickup: createPlaceSearch(root.querySelector('[data-search="pickup"]'), {
            config,
            near: () => points.pickup || points.destination,
            onSelect: (p) => place('pickup', { lat: p.lat, lng: p.lng, label: p.label }),
        }),
        destination: createPlaceSearch(root.querySelector('[data-search="destination"]'), {
            config,
            near: () => points.pickup || points.destination,
            onSelect: (p) => place('destination', { lat: p.lat, lng: p.lng, label: p.label }),
        }),
    };

    bindLocateButton(root.querySelector('[data-locate]'), locateStatus, (fix) => {
        map.showAccuracy(fix);
        searches.pickup.setValue('Your location');
        place('pickup', { lat: fix.lat, lng: fix.lng, label: 'Your location' });
    });

    followToggle.addEventListener('change', () => map.followDriver(followToggle.checked));

    simulateButton.addEventListener('click', () => {
        clearInterval(simulation);

        // Along the road route when there is one; otherwise along the straight line, which the label says.
        const path = routePath.length > 1 ? routePath : [points.pickup, points.destination];
        const total = pathLength(path);
        let travelled = 0;
        simulateButton.textContent = 'Restart simulated driver';
        root.querySelector('[data-simulation-note]').textContent = routePath.length > 1
            ? 'Simulated driver moving along the road route at 45 km/h. Demo only: not a real driver.'
            : 'Simulated driver moving along a straight line (no routing provider). Demo only: not a real driver.';

        const tick = () => {
            const position = pointAlong(path, travelled);
            map.setDriver(position);
            travelled += 12.5 * 2;

            if (travelled > total + 25) {
                clearInterval(simulation);
            }
        };

        tick();
        simulation = setInterval(tick, 2000);
    });

    setSummary('Choose a pickup and a destination, or tap the map twice.');
}
