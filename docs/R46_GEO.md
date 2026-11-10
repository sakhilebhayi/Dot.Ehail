# R-46 geo domain: maps, routing, geocoding, zones, locations, deviation

Owner: geo agent. Code: `app/Domain/Geo/**`, `resources/js/geo/**`, `resources/css/geo/geo.css`, `config/ehail-geo.php`, `routes/ehail/geo.php`, migration `2026_11_10_600000_create_geo_tables.php`. Research and provider policies: `docs/R46_RESEARCH.md` ("Maps and location"). Decisions D-200..D-214.

## 1. Providers and what the owner must set

All keys live in `.env` on the server. The browser only ever calls `/geo/*` on Dot.Ehail; the one exception is map tiles (images), so a keyed tile URL must be restricted by HTTP referrer at the provider.

| Purpose | Default with no key | To switch on the real thing | Env |
|---|---|---|---|
| Road routes (distance, duration, polyline) | **Off** (`none`). Booking shows "Route pricing is unavailable: the operator has not configured a routing provider." and pricing offers no route fare. | Pick one: `google` (Routes API, server key), `mapbox` (Directions), `ors` (openrouteservice key), `osrm` / `valhalla` (an operator-run or contracted server URL) | `EHAIL_ROUTING_DRIVER`, `EHAIL_GOOGLE_MAPS_SERVER_KEY` / `EHAIL_MAPBOX_SERVER_TOKEN` / `EHAIL_ORS_KEY` / `EHAIL_OSRM_URL` / `EHAIL_VALHALLA_URL` |
| Address search and reverse | Public OSM **Nominatim**: search when the user presses Enter or Search (no autocomplete, policy), reverse on map tap; 1 request per second for the whole app; answers cached 24 h | `google`, `mapbox`, `ors`, or `photon` (own server) turn on search-as-you-type automatically | `EHAIL_GEOCODING_DRIVER` (+ the key/URL above), `EHAIL_NOMINATIM_URL` / `EHAIL_NOMINATIM_RPS` for a self-hosted Nominatim |
| Map tiles | OSMF raster tiles with attribution and "Report a map issue" | Any raster XYZ provider: set the URL template and its attribution text | `EHAIL_TILES_URL`, `EHAIL_TILES_ATTRIBUTION`, `EHAIL_TILES_MAX_ZOOM`, `EHAIL_TILES_SIZE` (256/512), `EHAIL_TILES_KIND` (`osm` adds the OSM issue link) |
| Identification | `Dot.Ehail/1.0 (+APP_URL)` User-Agent on every server request (Nominatim and OSM policies) | | `EHAIL_GEO_USER_AGENT` |

Recommended production setup for South Africa: Google Routes (Essentials) + Google Places Text Search for the best SA address coverage, or ORS for the cheapest real routing. Before traffic grows, move tiles to a contracted provider (Google Map Tiles 2D, Mapbox Static Tiles, MapTiler, Stadia) because the OSMF server gives no SLA and may withdraw access from commercial users.

Limits that apply today:
- Nominatim public: 1 request/s total; a second search inside the same second answers 429 "busy" and the browser retries once after `Retry-After`. Fine for launch traffic, not for a city at scale.
- Route cache: identical requests (coordinates rounded to 4 decimals, ~11 m) reuse a stored route for 10 min (`EHAIL_ROUTING_CACHE_TTL`).
- Circuit breaker: 5 provider failures in 60 s open the breaker for 60 s; callers get "temporarily unavailable" immediately instead of stacked timeouts. Timeouts: 4 s per request (`EHAIL_ROUTING_TIMEOUT`, `EHAIL_GEOCODING_TIMEOUT`).
- Per-user limits (in `EhailGeoServiceProvider::boot`): search/reverse 40/min, route 20/min, reads 90/min, driver location 1 post per 2 s.

## 2. Contract for other domains (architecture section 3)

- `RoutePlanner::route(LatLng, LatLng, ?RouteOptions): RouteResult {distanceM, durationS, polyline (precision 5), provider, kind='road', fromCache}` or throws `RoutingUnavailable {reason: not_configured|timeout|provider_error|circuit_open|no_route|invalid_response}`. `isConfigured()` lets a UI say so before asking.
- `Geocoder::search(string, ?LatLng near, ?string country='ZA'): Place[]`, `reverse(LatLng): ?Place`, `supportsAutocomplete()`; throws `GeocodingUnavailable {reason: not_configured|busy|timeout|provider_error|circuit_open}`.
- `ZoneResolver::serviceAreaFor(LatLng): ?ServiceArea` (smallest active area wins), `zonesFor(LatLng, ?kind): Zone[]`, `isServiced`, `isRestricted`. `Zone::fee_code` is the pricing hook (seeded airports carry `airport`, `meta.iata`).
- `LocationStore::recordMany(User, LocationSample[], ?Ride)`, `latest(int[])`, `trail(Ride)`, `nearbyDrivers(LatLng, radius_m, {only, exclude, max_age_seconds, limit}): NearbyDriver[]`.
- `DistanceMeter::actual(Ride): TripDistance`, `measure(points)`, `straightLine(Ride)` (informational).
- `DeviationDetector::check(Ride): ?Deviation`; `DeviationMonitor::evaluate(Ride)` raises the events (below).
- `RideRoutePlanner::attach(Ride, RouteResult)`: pricing or dispatch should call this with the route they quoted so deviation is measured against the route the passenger agreed to. If nothing is attached, `planFor()` plans pickup to drop-off on first need.
- Seams other owners rebind: `DriverPresence` (dispatch: online state and active ride; default reads `driver_profiles.is_online` and `rides`), `TrailRetentionHold` (safety: rides under an open incident keep their raw trail; default none).
- Events for safety: `App\Domain\Geo\Events\RouteDeviationDetected {rideId, driverId, offRouteMetres, lat, lng, occurredAt, context{threshold_m, samples, planned_provider, max_off_route_m}}` and `UnusualStopDetected {rideId, driverId, stoppedSeconds, lat, lng, occurredAt, context{stop_radius_m, samples, distance_to_dropoff_m}}`. At most one per ride per kind per 10 min.

### HTTP (authenticated, inside the main auth group)

| Route | Purpose | Notes |
|---|---|---|
| `GET /geo/config` | Client config: tiles URL and attribution, routing configured or not (with the exact unavailable sentence), geocoding provider and autocomplete flag, endpoints | No secrets (tested) |
| `GET /geo/search?q=&lat=&lng=` | Proxy search | 422 JSON on bad input; 429 + `Retry-After: 1` when the provider ceiling is hit; 503 with `{reason, message}` when unavailable |
| `GET /geo/reverse?lat=&lng=` | Proxy reverse | same |
| `GET /geo/route?from_lat=&from_lng=&to_lat=&to_lng=` | Road route + `pickup_serviced` | 503 `not_configured` with the honest sentence |
| `GET /geo/zones?lat=&lng=` | Service area and zones at a point | |
| `POST /geo/driver/location` | Driver device posts `{samples: [{lat, lng, accuracy, speed?, heading?, recorded_at (epoch ms)}]}`, 1..20 per post | Approved driver who is online or on an active ride; else 409 `not_online` |
| `GET /geo/rides/{ride}/driver-location` | The assigned driver's latest fix for one ride | Passenger only while the ride is active (403 `not_tracking` otherwise); the driver; operators; everyone else 404 |
| `GET /geo/demo` | Development demo | 404 unless `APP_ENV=local` |

Front end: `resources/js/geo/index.js` (Vite entry, 4.9 KB gzip + 2.1 KB CSS) exports `mountMap(el, {style, center, zoom, onMapClick})`, `createPlaceSearch(root, {config, near, onSelect})`, `bindLocateButton`, `locateOnce`, `loadGeoConfig`, `getJson`, geometry helpers. `mountMap` lazy-loads `map.js` (Leaflet + CSS: 45.6 KB + 2.6 KB gzip). The controller has `setPickup`, `setDestination`, `setDriver({lat, lng, heading})` (glides between fixes, instant under reduced motion or for jumps over 1.5 km), `followDriver(bool)`, `drawRoute(polyline)`, `showAccuracy({lat, lng, accuracy})`, `fitToContent`, `centreOn`, `setStatus`, `destroy`. Map look comes from `--eh-map-*` tokens (route, casing, pickup, dropoff, driver) and `--eh-map-tile-filter` (raster tiles cannot be recoloured; dark theme inverts them). Pages that use "use my location" or post driver positions must put `App\Domain\Geo\Http\Middleware\AllowGeolocation` on their route (see section 6).

## 3. Distance vocabulary (what each number means)

| Name | Source | Used for |
|---|---|---|
| **Estimated route distance** | `RouteResult.distanceM` from a routing provider, before the trip | Quotes (pricing). Without a routing provider there is none, and no route fare. |
| **Actual travelled distance** | `TripDistance.travelledM`: the filtered GPS trail during `in_progress`, gaps bridged by straight lines | Records, disputes |
| **Straight-line distance** | `DistanceMeter::straightLine` (haversine pickup to drop-off) | Information only (e.g. "about 4 km away"). **Never billed** (D-4). |
| **Billable distance** | `TripDistance.billableM`: travelled, with gaps longer than 45 s and 400 m re-estimated by a road route when routing is configured | Final fare (pricing's `FareFinaliser`). When a gap could not be routed it stays a straight line (shorter than the road, the passenger's favour), `method = gps_with_straight_gaps`, flag `unresolved_gap`, quality `degraded`: pricing should keep the quoted fare. |

Filtering in `DistanceMeter::measure`: fixes with accuracy worse than 50 m are dropped (`inaccurate_points`); a fix implying more than 70 m/s from the last kept point is a GPS jump and dropped (`speed_outliers`); movement smaller than the two fixes' accuracy radii is jitter and ignored, measured from the last counted point so slow traffic still accumulates. Methods: `gps`, `gps_with_route_gaps`, `gps_with_straight_gaps`, `no_data`, `stored_summary` (after the raw trail is purged).

## 4. Location ingest rules

Per sample: accuracy worse than 150 m is `inaccurate`; more than 30 s in the future is `future` (device clock); older than 10 min is `stale`; not newer than the previous accepted fix is `out_of_order`; implying more than 70 m/s after allowing for both accuracies is `implausible_jump`, unless three such fixes arrive in a row (the earlier fix was the wrong one) or the previous fix is over 2 min old. Only fixes up to 60 s old move the driver's latest row (the marker); older queued fixes from a dead zone still fill the trip trail. Trip points are stored only while the driver's ride is `in_progress`, at most one per 3 s or 25 m.

## 5. Retention (POPIA minimality)

- `driver_locations`: one row per driver, overwritten on each fix; deleted after 7 days of silence.
- `trip_points`: raw trail of trips only; after **90 days** (`EHAIL_TRIP_POINTS_RETENTION_DAYS`) the daily `ehail:geo-purge` (scheduled 02:40 from `EhailGeoServiceProvider`) stores the trip's distance summary on `ride_routes` and deletes the points, unless safety holds the ride.
- Passenger live position: **never stored**. The browser's "use my location" result goes only into the booking form as a pickup point; the only passenger coordinates in the database are a ride's pickup and drop-off.
- `route_cache`: expired rows deleted daily.
- Access: see section 2 (`LocationAccess`). Operator reads of driver positions are not audit-logged per poll (it would flood the log); safety-domain incident views should log their own access.

## 6. What other owners must do (seams, not edits)

1. **Security headers (shared file, not geo-owned):** the global `Permissions-Policy` sends `geolocation=()`, which blocks "use my location" and driver position reporting in every browser. Pages that need it must use the `AllowGeolocation` route middleware (sets `geolocation=(self)`), or SecurityHeaders should change globally. The report-only CSP reports every tile (`img-src` lacks the tile host): before the CSP is enforced, add `GeoCsp::imgSources()` (today `https://tile.openstreetmap.org`) to `img-src`, or the map goes blank.
2. **Dispatch:** rebind `DriverPresence` to `AvailabilityService`; use `LocationStore::nearbyDrivers` for matching; call `RideRoutePlanner::attach` when a quote's route is bound to the ride.
3. **Pricing:** call `RoutePlanner::route` and handle `RoutingUnavailable` by refusing a route-based quote; read `Zone::fee_code` via `ZoneResolver::zonesFor`; use `DistanceMeter::actual` at completion and keep the quote when `quality !== 'good'` or `unresolved_gap` is flagged.
4. **Safety:** listen to `RouteDeviationDetected` and `UnusualStopDetected`; bind `TrailRetentionHold` for open incidents.
5. **Passenger/driver UIs:** import from `resources/js/geo/index.js`; the driver app posts samples every 4 s while online (batched after a dead zone) and stops when offline.
6. **Deploy:** run `php artisan ehail:geo-reference-areas` once (creates the approximate SA areas and airport zones; never overwrites edits).

## 7. Reference areas (approximate, review before launch)

Seeded by `ehail:geo-reference-areas` / `GeoReferenceAreasSeeder`, all flagged `is_approximate` with the note "Approximate outline from public coordinates; review before launch.": Greater Johannesburg (incl. OR Tambo), Pretoria (Tshwane), Cape Town, Durban (eThekwini, incl. King Shaka); airport zones JNB, CPT, DUR (`kind=airport`, `fee_code=airport`). A landmark test (`ZonesTest`) checks CBDs, suburbs at the edges, the three terminals, Bloemfontein (not served) and open sea. The outlines are generous hand-drawn polygons, not municipal boundaries.

## 8. Why not PostGIS

The shared cPanel host's PostgreSQL/MySQL may not have PostGIS, and tests run on SQLite. Nearby search is a bounding-box query on indexed `lat`/`lng` columns followed by an exact haversine filter in PHP, which is portable and fast enough for the stated load (500 online drivers): the box returns only the drivers in a few km. Polygons are JSON with a stored bounding box; containment is ray casting in PHP after a bbox query.

## 9. Known limits

- No live traffic unless the routing provider supplies it (Google Routes Essentials is traffic-unaware; Pro is traffic-aware and dearer).
- The OSMF tile server has no SLA; raster tiles look plainer than a vector style and cannot follow the `--eh-map-land/road/water` palette (those tokens apply if a vector engine is adopted later; the switch is MapLibre + OpenFreeMap at ~440 KB gzip).
- Unusual-stop detection needs the driver's device to keep posting; a phone that goes silent is a different signal (lost contact) that dispatch/safety should detect from the latest-row age.
- Route deviation needs a planned road route; with routing off, only unusual stops are detected.
