<?php

/*
|--------------------------------------------------------------------------
| Dot.Analytics (daily aggregate snapshots)
|--------------------------------------------------------------------------
| Once a day (ehail:publish-metrics) this app posts, server to server, signed
| snapshots of aggregate numbers to Dot.Analytics (POST /api/ingest/snapshots),
| queued: one per fleet-owning company, plus one platform-wide snapshot for the
| operating company named below. Both the URL and the secret must be set or
| nothing is sent. The secret is this app's ANALYTICS_INGEST_SECRET_<KEY> value
| in Dot.Analytics' .env. Aggregates only: counts and one average.
*/

return [
    'url' => env('ANALYTICS_INGEST_URL'),
    'secret' => env('ANALYTICS_INGEST_SECRET'),

    // Dot.Analytics' name for this app (it picks the signing secret by this key).
    'platform' => env('ANALYTICS_PLATFORM_KEY', 'ehail'),

    // The InfoDot company uuid of whoever OPERATES Dot.Ehail (the platform operators' company).
    // Rides are requested by passengers and only belong to a fleet once a driver accepts, so the
    // platform-wide numbers (requests, expiries) have no fleet owner. They are published under this
    // company and only this one. Blank = no platform-wide snapshot is published.
    'company_uuid' => env('ANALYTICS_COMPANY_UUID'),

    // Seconds, for connecting and for the whole request.
    'timeout' => 5,

    // After `threshold` consecutive failed calls, stop calling for `pause_seconds`.
    'circuit' => [
        'threshold' => 5,
        'pause_seconds' => 60,
    ],
];
