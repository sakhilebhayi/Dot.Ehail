// The Dot.Ehail map: Leaflet 1.9 (BSD-2) with raster tiles from the configured provider. This module is only ever
// loaded through a dynamic import (see index.js), so Leaflet and its stylesheet stay out of the first view.

import * as L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { boundsOf, decodePolyline } from './geometry.js';
import { Follower } from './follow.js';
import { stillMap } from './still.js';

const reducedMotion = () => typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;

function stationIcon(kind, label) {
    return L.divIcon({
        className: 'eh-map-marker',
        html: `<span class="eh-map-station eh-map-station--${kind}" aria-hidden="true"></span>`,
        iconSize: [28, 28],
        iconAnchor: [14, 14],
        tooltipAnchor: [0, -16],
        // Not focusable: the place names are in the page text next to the map.
        title: label,
    });
}

function driverIcon() {
    return L.divIcon({
        className: 'eh-map-marker',
        html: '<span class="eh-map-driver" aria-hidden="true"><span class="eh-map-driver__heading"></span><span class="eh-map-driver__body"></span></span>',
        iconSize: [36, 36],
        iconAnchor: [18, 18],
    });
}

/**
 * Mounts a map in el. config is the /geo/config payload. Returns a controller; every method is safe to call in
 * any order. Throws only if el is missing.
 */
export function createMap(el, config, { center, zoom, onMapClick } = {}) {
    if (!el) {
        throw new Error('mountMap needs an element');
    }

    const motion = !reducedMotion();
    const start = center || config.defaultCenter;

    el.classList.add('eh-map');
    el.setAttribute('role', 'region');
    el.setAttribute('aria-label', el.getAttribute('aria-label') || 'Map');

    const map = L.map(el, {
        center: [start.lat, start.lng],
        zoom: zoom ?? config.defaultZoom ?? 12,
        zoomControl: true,
        attributionControl: true,
        preferCanvas: true,
        zoomAnimation: motion,
        fadeAnimation: motion,
        markerZoomAnimation: motion,
        inertia: motion,
        tapTolerance: 20,
    });

    map.attributionControl.setPrefix(false);
    // Top right: at the bottom the passenger sheet and the ops panels covered it, hiding the OSM credit (which must
    // stay visible) and its links from keyboard users (R-46 a11y, D-1714).
    map.attributionControl.setPosition('topright');
    if (!motion) stillMap(map);

    const tiles = L.tileLayer(config.tiles.url, {
        maxZoom: config.tiles.maxZoom,
        tileSize: config.tiles.tileSize,
        zoomOffset: config.tiles.tileSize === 512 ? -1 : 0,
        attribution: config.tiles.attribution + (config.tiles.reportIssueUrl ? ` | <a href="${config.tiles.reportIssueUrl}" target="_blank" rel="noopener">Report a map issue</a>` : ''),
        // No look-ahead beyond the visible area (OSMF tile policy forbids prefetching) and fewer requests on slow data.
        keepBuffer: 1,
        updateWhenIdle: true,
        crossOrigin: false,
    }).addTo(map);

    const banner = document.createElement('p');
    banner.className = 'eh-map-banner';
    banner.setAttribute('role', 'status');
    banner.hidden = true;
    el.appendChild(banner);

    const setStatus = (kind, message) => {
        banner.hidden = !message;
        banner.textContent = message || '';
        banner.dataset.kind = kind || '';
    };

    let tileErrors = 0;
    let tilesLoaded = 0;
    tiles.on('tileerror', () => {
        tileErrors++;

        if (tilesLoaded === 0 && tileErrors >= 4) {
            setStatus('caution', 'The map could not load. Search and routes still work.');
        }
    });
    tiles.on('tileload', () => {
        tilesLoaded++;

        if (banner.dataset.kind === 'caution' && navigator.onLine !== false) {
            setStatus(null, null);
        }
    });

    const onOffline = () => setStatus('caution', 'You are offline. The map and positions update when the connection returns.');
    const onOnline = () => {
        setStatus(null, null);
        tiles.redraw();
    };
    window.addEventListener('offline', onOffline);
    window.addEventListener('online', onOnline);

    if (navigator.onLine === false) {
        onOffline();
    }

    if (onMapClick) {
        map.on('click', (event) => onMapClick({ lat: event.latlng.lat, lng: event.latlng.lng }));
    }

    const layers = { pickup: null, destination: null, driver: null, routeCasing: null, route: null, accuracy: null, accuracyDot: null };
    let routePoints = [];
    let following = false;

    const placeStation = (key, point, kind, label) => {
        if (!point) {
            layers[key]?.remove();
            layers[key] = null;

            return;
        }

        if (layers[key]) {
            layers[key].setLatLng([point.lat, point.lng]);
        } else {
            layers[key] = L.marker([point.lat, point.lng], { icon: stationIcon(kind, label), keyboard: false, interactive: false }).addTo(map);
        }
    };

    const follower = new Follower((position) => {
        if (!layers.driver) {
            layers.driver = L.marker([position.lat, position.lng], { icon: driverIcon(), keyboard: false, interactive: false, zIndexOffset: 500 }).addTo(map);
        } else {
            layers.driver.setLatLng([position.lat, position.lng]);
        }

        const heading = layers.driver.getElement()?.querySelector('.eh-map-driver__heading');

        if (heading) {
            heading.style.transform = `rotate(${Math.round(position.heading || 0)}deg)`;
            heading.hidden = !Number.isFinite(position.heading);
        }
    }, { reducedMotion: !motion });

    const controller = {
        map,
        setStatus,
        setPickup(point, label = 'Pickup') {
            placeStation('pickup', point, 'pickup', label);
        },
        setDestination(point, label = 'Destination') {
            placeStation('destination', point, 'destination', label);
        },
        setDriver(position) {
            if (!position) {
                follower.stop();
                layers.driver?.remove();
                layers.driver = null;
                follower.drawn = null;

                return;
            }

            follower.moveTo(position);

            // Following pans towards the new fix while the marker glides there, so the two move together.
            if (following) {
                map.panTo([position.lat, position.lng], { animate: motion, duration: 1, easeLinearity: 0.5 });
            }
        },
        followDriver(on = true) {
            following = on;

            if (on && follower.drawn) {
                map.panTo([follower.drawn.lat, follower.drawn.lng], { animate: motion });
            }
        },
        drawRoute(polyline) {
            layers.route?.remove();
            layers.routeCasing?.remove();
            layers.route = layers.routeCasing = null;
            routePoints = [];

            if (!polyline) {
                return [];
            }

            routePoints = typeof polyline === 'string' ? decodePolyline(polyline) : polyline;
            const latLngs = routePoints.map((p) => [p.lat, p.lng]);
            const styles = getComputedStyle(el);
            const routeColour = styles.getPropertyValue('--eh-map-route').trim() || '#0b6a3c';
            const casingColour = styles.getPropertyValue('--eh-map-route-casing').trim() || '#ffffff';

            layers.routeCasing = L.polyline(latLngs, { color: casingColour, weight: 9, opacity: 1, lineCap: 'round', lineJoin: 'round', interactive: false }).addTo(map);
            layers.route = L.polyline(latLngs, { color: routeColour, weight: 5, opacity: 1, lineCap: 'round', lineJoin: 'round', interactive: false }).addTo(map);

            return routePoints;
        },
        showAccuracy(fix) {
            layers.accuracy?.remove();
            layers.accuracyDot?.remove();
            layers.accuracy = layers.accuracyDot = null;

            if (!fix) {
                return;
            }

            const colour = getComputedStyle(el).getPropertyValue('--eh-map-driver').trim() || '#1d5bd6';
            layers.accuracy = L.circle([fix.lat, fix.lng], { radius: Math.max(5, fix.accuracy || 0), color: colour, weight: 1, opacity: 0.6, fillColor: colour, fillOpacity: 0.12, interactive: false }).addTo(map);
            layers.accuracyDot = L.circleMarker([fix.lat, fix.lng], { radius: 6, color: '#ffffff', weight: 2, fillColor: colour, fillOpacity: 1, interactive: false }).addTo(map);
        },
        fitToContent({ maxZoom = 16 } = {}) {
            const points = [...routePoints];

            for (const key of ['pickup', 'destination', 'driver']) {
                const layer = layers[key];

                if (layer) {
                    const { lat, lng } = layer.getLatLng();
                    points.push({ lat, lng });
                }
            }

            const bounds = boundsOf(points);

            if (!bounds) {
                return;
            }

            if (points.length === 1) {
                map.setView(bounds[0], maxZoom, { animate: motion });
            } else {
                map.fitBounds(bounds, { padding: [48, 48], maxZoom, animate: motion });
            }
        },
        centreOn(point, zoomLevel = 16) {
            map.setView([point.lat, point.lng], zoomLevel, { animate: motion });
        },
        invalidateSize() {
            map.invalidateSize();
        },
        destroy() {
            follower.stop();
            window.removeEventListener('offline', onOffline);
            window.removeEventListener('online', onOnline);
            map.remove();
        },
    };

    return controller;
}
