<?php

/*
|--------------------------------------------------------------------------
| R-46 integration (docs/R46_INTEGRATION.md)
|--------------------------------------------------------------------------
| Settings that belong to no single domain: they decide how the domains meet.
*/

return [
    // A passenger may cancel free when the assigned driver has not arrived this many seconds after the
    // pickup ETA promised in the accepted offer (pricing's "driver_late" waiver, D-703).
    'driver_late_grace_seconds' => (int) env('EHAIL_DRIVER_LATE_GRACE_SECONDS', 300),

    // Paths whose every response is JSON: errors there render as JSON even for a client that did not send
    // Accept: application/json (D-711). Pages that also serve HTML (receipts, trip share) are not listed.
    'json_paths' => [
        'api/*',
        'pricing/*',
        'ops/pricing/*',
        'fare-estimate',
        'fare-estimate/*',
        'geo/config',
        'geo/search',
        'geo/reverse',
        'geo/route',
        'geo/zones',
        'geo/driver/*',
        'geo/rides/*',
    ],
];
