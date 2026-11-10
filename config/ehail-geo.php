<?php

/*
|--------------------------------------------------------------------------
| Dot.Ehail geo: routing, geocoding, map tiles, locations (R-46, docs/R46_GEO.md)
|--------------------------------------------------------------------------
| Every provider is chosen by env. With nothing set:
|   - routing is OFF ('none'): no road route, so no route-based fare. Straight-line distance is never billed.
|   - geocoding uses the public OSM Nominatim server for search-on-submit and reverse lookups, held to its
|     usage policy (1 request per second for the whole app, cached, identified, attributed, no autocomplete).
|   - map tiles come from the OpenStreetMap Foundation tile server, with its attribution, a valid Referer and no
|     prefetching or offline use. Switch to a contracted tile provider before traffic grows (see R46_GEO.md).
| Keys stay on the server: the browser calls /geo/search, /geo/reverse and /geo/route. Map tiles are the only
| thing the browser fetches from a provider directly; a keyed tile URL must be restricted by HTTP referrer.
*/

return [

    'user_agent' => env('EHAIL_GEO_USER_AGENT', 'Dot.Ehail/1.0 (+'.env('APP_URL', 'https://ehail.infodot.co.za').')'),

    'routing' => [
        // google | mapbox | ors | osrm | valhalla | none
        'driver' => env('EHAIL_ROUTING_DRIVER', 'none'),
        'timeout_seconds' => (float) env('EHAIL_ROUTING_TIMEOUT', 4),
        // Identical requests (coordinates rounded to ~11 m) reuse a stored route for this long.
        'cache_ttl_seconds' => (int) env('EHAIL_ROUTING_CACHE_TTL', 600),
        'cache_precision' => 4,
    ],

    'geocoding' => [
        // google | mapbox | ors | photon | nominatim | none
        'driver' => env('EHAIL_GEOCODING_DRIVER', 'nominatim'),
        'timeout_seconds' => (float) env('EHAIL_GEOCODING_TIMEOUT', 4),
        'country' => env('EHAIL_GEOCODING_COUNTRY', 'ZA'),
        'language' => env('EHAIL_GEOCODING_LANGUAGE', 'en'),
        'limit' => 6,
        // Seconds a search/reverse answer is reused. Google and Mapbox (temporary geocoding) restrict storing
        // their results, so their answers are not cached unless the operator changes this deliberately.
        'cache_ttl_seconds' => [
            'nominatim' => 86400,
            'photon' => 86400,
            'ors' => 3600,
            'google' => 0,
            'mapbox' => 0,
        ],
    ],

    'circuit_breaker' => [
        // After this many failures inside window_seconds the provider is skipped for cooldown_seconds.
        'failures' => 5,
        'window_seconds' => 60,
        'cooldown_seconds' => 60,
    ],

    'providers' => [
        'google' => [
            'key' => env('EHAIL_GOOGLE_MAPS_SERVER_KEY'),
        ],
        'mapbox' => [
            'token' => env('EHAIL_MAPBOX_SERVER_TOKEN'),
        ],
        'ors' => [
            'key' => env('EHAIL_ORS_KEY'),
            'url' => env('EHAIL_ORS_URL', 'https://api.openrouteservice.org'),
        ],
        'osrm' => [
            // Self-hosted or contracted OSRM. The public demo server is non-commercial only and is never a default.
            'url' => env('EHAIL_OSRM_URL'),
        ],
        'valhalla' => [
            'url' => env('EHAIL_VALHALLA_URL'),
        ],
        'photon' => [
            'url' => env('EHAIL_PHOTON_URL'),
        ],
        'nominatim' => [
            'url' => env('EHAIL_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
            // The public server allows 1 request per second in total. A self-hosted instance can raise this.
            'requests_per_second' => (int) env('EHAIL_NOMINATIM_RPS', 1),
        ],
    ],

    'tiles' => [
        // Raster XYZ template rendered with Leaflet. Default: OSMF tile server (policy: attribution, Referer,
        // no prefetch, no offline, access may be withdrawn for commercial services). Replace with a contracted
        // provider by setting the URL and attribution (a key in the URL must be restricted by HTTP referrer).
        'url' => env('EHAIL_TILES_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'attribution' => env('EHAIL_TILES_ATTRIBUTION', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'),
        'max_zoom' => (int) env('EHAIL_TILES_MAX_ZOOM', 19),
        'tile_size' => (int) env('EHAIL_TILES_SIZE', 256),
        // 'osm' adds the OSMF "Report a map issue" link the tile policy recommends.
        'kind' => env('EHAIL_TILES_KIND', 'osm'),
    ],

    'locations' => [
        // A sample whose reported accuracy is worse than this is rejected.
        'max_accuracy_m' => (int) env('EHAIL_LOCATION_MAX_ACCURACY_M', 150),
        // The latest-location row only moves for samples at most this old (seconds).
        'max_age_seconds' => 60,
        // Queued samples (tunnel, dead zone) still count towards an active trip's trail up to this age.
        'max_backfill_seconds' => 600,
        // A sample stamped further than this in the future is refused (device clock wrong).
        'max_future_skew_seconds' => 30,
        // Implied speed between consecutive samples above this (m/s) is a GPS jump, not driving (250 km/h).
        'max_speed_mps' => 70,
        // Store a trip point when this many seconds or metres have passed since the last stored one.
        'trip_point_min_interval_seconds' => 3,
        'trip_point_min_distance_m' => 25,
        // Drivers whose latest sample is older than this are not "nearby" for matching.
        'nearby_max_age_seconds' => 60,
        'max_samples_per_post' => 20,
        // Raw trip points are kept this many days, then the trip's distance summary is kept and points deleted.
        'trip_points_retention_days' => (int) env('EHAIL_TRIP_POINTS_RETENTION_DAYS', 90),
        // Latest-location rows of drivers who have sent nothing for this many days are deleted.
        'driver_location_retention_days' => 7,
    ],

    'distance' => [
        'max_point_accuracy_m' => 50,
        'jitter_floor_m' => 8,
        // A gap longer than this (seconds) AND wider than gap_distance_m is bridged with a road route when possible.
        'gap_seconds' => 45,
        'gap_distance_m' => 400,
    ],

    'deviation' => [
        // Off the planned route by more than this (metres, plus the sample's accuracy) ...
        'threshold_m' => 300,
        // ... for this many consecutive good samples.
        'consecutive_samples' => 3,
        // Stationary (within stop_radius_m) for this long during a trip, away from the drop-off, is unusual.
        'stop_seconds' => 300,
        'stop_radius_m' => 40,
        'dropoff_radius_m' => 200,
        // The same kind of event for the same ride is not raised again within this many seconds.
        'cooldown_seconds' => 600,
    ],

];
