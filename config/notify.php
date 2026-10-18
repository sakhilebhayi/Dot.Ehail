<?php

/*
|--------------------------------------------------------------------------
| Dot.Notify (email-worthy events)
|--------------------------------------------------------------------------
| Events this app already raises in-app are also posted, server to server, to
| Dot.Notify's ingest API (POST /api/ingest/events), queued and signed. Both
| the URL and the secret must be set or nothing is sent. The secret is this
| app's NOTIFY_INGEST_SECRET_<KEY> value in Dot.Notify's .env. Notify only
| emails when the recipient's company has also enabled the event there.
*/

return [
    'url' => env('NOTIFY_INGEST_URL'),
    'secret' => env('NOTIFY_INGEST_SECRET'),

    // Dot.Notify's name for this app (it picks the signing secret by this key).
    'platform' => env('NOTIFY_PLATFORM_KEY', 'ehail'),

    // Seconds, for connecting and for the whole request.
    'timeout' => 5,

    // After `threshold` consecutive failed calls, stop calling for `pause_seconds`.
    'circuit' => [
        'threshold' => 5,
        'pause_seconds' => 60,
    ],
];
