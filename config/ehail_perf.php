<?php

/*
|--------------------------------------------------------------------------
| R-46 performance, polling budget and health (docs/R46_PERF.md)
|--------------------------------------------------------------------------
| Measured, not guessed: every figure below is explained in docs/R46_PERF.md
| with the load-test numbers behind it.
*/

return [

    // A database session row is rewritten only when its contents changed or its
    // last_activity is older than this many seconds. Polls do not change the
    // session, so a driver polling every 3 s costs one session write a minute
    // instead of twenty. 0 restores Laravel's write-on-every-request.
    'session_touch_seconds' => (int) env('EHAIL_SESSION_TOUCH_SECONDS', 60),

    // Adds X-Ehail-Queries / X-Ehail-Writes / X-Ehail-Ms response headers so the
    // load scripts can attribute cost per endpoint. Never on in production: the
    // provider refuses unless APP_ENV is local or testing.
    'probe' => (bool) env('EHAIL_PERF_PROBE', false),

    // Adaptive polling (Retry-After hints, seconds). The driver app follows them.
    'driver_poll' => [
        'offer' => (int) env('EHAIL_POLL_DRIVER_OFFER', 2),        // an offer is on screen: see withdrawal or expiry fast
        'trip' => (int) env('EHAIL_POLL_DRIVER_TRIP', 3),          // on a trip
        'idle' => (int) env('EHAIL_POLL_DRIVER_IDLE', 3),          // online, waiting for offers
        'paused' => (int) env('EHAIL_POLL_DRIVER_PAUSED', 15),     // paused: no offers can arrive
        'saver' => (int) env('EHAIL_POLL_DRIVER_SAVER', 6),        // phone asked to save data or battery low (idle only)
        'offline' => (int) env('EHAIL_POLL_DRIVER_OFFLINE', 30),
        // A sync without an accepted position refreshes the heartbeat at most this often (writes are what a shared
        // host pays for). Well inside the 45 s staleness window, so a parked driver stays matchable.
        // Parked (not moving), the app sends its position with a sync at most this often; moving, with every sync.
        'parked_post_every_s' => (int) env('EHAIL_DRIVER_PARKED_POST_EVERY_SECONDS', 15),
        'heartbeat_write_every_s' => (int) env('EHAIL_HEARTBEAT_WRITE_EVERY_SECONDS', 20),
    ],

    'passenger_poll' => [
        'searching' => (int) env('EHAIL_POLL_PASSENGER_SEARCHING', 3),
        'approaching' => (int) env('EHAIL_POLL_PASSENGER_APPROACHING', 3), // assigned, arriving, at pickup
        'in_progress' => (int) env('EHAIL_POLL_PASSENGER_IN_PROGRESS', 5),  // in the car: the map moves, nothing to decide
        'scheduled' => (int) env('EHAIL_POLL_PASSENGER_SCHEDULED', 30),
        'hidden' => (int) env('EHAIL_POLL_PASSENGER_HIDDEN', 15),           // tab in the background (the client applies it)
    ],

    // The passenger's driver ETA: a road route is asked for at most this often per ride
    // (or when the driver moved further than recompute_after_m since the last answer).
    // In between, the last answer is counted down by the time that passed.
    'eta_reuse_seconds' => (int) env('EHAIL_ETA_REUSE_SECONDS', 30),
    'eta_recompute_after_m' => (int) env('EHAIL_ETA_RECOMPUTE_AFTER_M', 400),

    // Health thresholds (ehail:health, /up/realtime, the ops status panel). Breaching one is "degraded";
    // breaching its critical value is "failing". Email goes to the ops address when configured.
    'health' => [
        'queue_lag_warn_s' => (int) env('EHAIL_HEALTH_QUEUE_LAG_WARN', 120),
        'queue_lag_fail_s' => (int) env('EHAIL_HEALTH_QUEUE_LAG_FAIL', 600),
        'failed_jobs_warn' => (int) env('EHAIL_HEALTH_FAILED_JOBS_WARN', 1),
        'failed_jobs_fail' => (int) env('EHAIL_HEALTH_FAILED_JOBS_FAIL', 25),
        'dispatch_tick_warn_s' => (int) env('EHAIL_HEALTH_TICK_WARN', 150),
        'dispatch_tick_fail_s' => (int) env('EHAIL_HEALTH_TICK_FAIL', 600),
        'schedule_warn_s' => (int) env('EHAIL_HEALTH_SCHEDULE_WARN', 150),
        'stale_driver_share_warn' => (float) env('EHAIL_HEALTH_STALE_DRIVER_WARN', 0.25),
        'stale_driver_share_fail' => (float) env('EHAIL_HEALTH_STALE_DRIVER_FAIL', 0.6),
        'webhook_lag_warn_s' => (int) env('EHAIL_HEALTH_WEBHOOK_LAG_WARN', 900),
        'webhook_lag_fail_s' => (int) env('EHAIL_HEALTH_WEBHOOK_LAG_FAIL', 3600),
        'notification_failed_warn' => (int) env('EHAIL_HEALTH_NOTIFY_FAILED_WARN', 5),
        'notification_failed_fail' => (int) env('EHAIL_HEALTH_NOTIFY_FAILED_FAIL', 50),
        'notification_due_warn' => (int) env('EHAIL_HEALTH_NOTIFY_DUE_WARN', 200),
        // Email the ops address when the overall state worsens (at most once per this many minutes per check).
        'alert_email' => env('EHAIL_HEALTH_ALERT_EMAIL'),
        'alert_every_minutes' => (int) env('EHAIL_HEALTH_ALERT_EVERY_MINUTES', 30),
    ],

];
