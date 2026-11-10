<?php

/*
|--------------------------------------------------------------------------
| R-46 dispatch, lifecycle and real-time polling (docs/R46_DISPATCH.md)
|--------------------------------------------------------------------------
| Every figure here is a policy the operator may tune. The dispatcher logs
| the weights it used on every offer (ride_offers.score_terms) so a change
| can be measured afterwards.
*/

return [

    // Sequential offers: one driver at a time, this long to answer.
    'offer_timeout_seconds' => (int) env('EHAIL_OFFER_TIMEOUT_SECONDS', 15),

    // Search rings in metres; the dispatcher widens to the next ring when the
    // current one has no untried eligible driver.
    'search_radii_m' => array_map('intval', explode(',', (string) env('EHAIL_SEARCH_RADII_M', '2000,4000,7000,10000'))),

    // The search window (how long a ride may look for a driver before it expires) and the
    // reassignment cap stay where they were before R-46: config/ehail.php ride_expiry_minutes
    // and max_reassignments.

    // When nobody is eligible, look again after this many seconds (polls and the scheduler call tick()).
    'retry_seconds' => (int) env('EHAIL_DISPATCH_RETRY_SECONDS', 3),

    // How many candidates are scored per tick (closest by straight line first).
    'candidate_limit' => (int) env('EHAIL_DISPATCH_CANDIDATE_LIMIT', 20),

    // Concurrency lease per ride while a tick works on it.
    'lease_seconds' => (int) env('EHAIL_DISPATCH_LEASE_SECONDS', 5),

    // A driver with no heartbeat (and no position) for this long is stale and is not offered rides.
    'heartbeat_stale_seconds' => (int) env('EHAIL_HEARTBEAT_STALE_SECONDS', 45),

    // Straight-line ETA used ONLY to rank drivers when no road router answers within budget:
    // distance x detour factor / speed, capped. Never used for fares.
    'eta' => [
        'detour_factor' => (float) env('EHAIL_ETA_DETOUR_FACTOR', 1.4),
        'speed_kmh' => (float) env('EHAIL_ETA_SPEED_KMH', 25),
        'max_seconds' => (int) env('EHAIL_ETA_MAX_SECONDS', 3600),
        'router_budget_ms' => (int) env('EHAIL_ETA_ROUTER_BUDGET_MS', 400),
        'router_max_candidates' => (int) env('EHAIL_ETA_ROUTER_MAX_CANDIDATES', 5),
    ],

    // Scoring: cost in seconds-equivalent; lowest cost gets the offer.
    //   cost = eta_s
    //        - idle_weight x min(idle_s, idle_cap_s)
    //        + clamp(trip_balance_s_per_trip x (driver trips today - median of candidates), +-trip_balance_cap_s)
    //        + cooldown_s   (only while a driver is inside a decline cooldown; capped, never exclusion)
    'weights' => [
        'eta' => (float) env('EHAIL_W_ETA', 1.0),
        'idle_weight' => (float) env('EHAIL_W_IDLE', 0.1),
        'idle_cap_s' => (int) env('EHAIL_W_IDLE_CAP_S', 1800),
        'trip_balance_s_per_trip' => (int) env('EHAIL_W_TRIP_BALANCE_S', 30),
        'trip_balance_cap_s' => (int) env('EHAIL_W_TRIP_BALANCE_CAP_S', 120),
        'cooldown_s' => (int) env('EHAIL_W_COOLDOWN_S', 60),
    ],

    // Declines/timeouts never remove a driver from dispatch. After this many in the window,
    // the cooldown penalty above applies for cooldown_minutes, then disappears.
    'decline_cooldown' => [
        'threshold' => (int) env('EHAIL_DECLINE_COOLDOWN_THRESHOLD', 3),
        'window_minutes' => (int) env('EHAIL_DECLINE_COOLDOWN_WINDOW_MINUTES', 10),
        'cooldown_minutes' => (int) env('EHAIL_DECLINE_COOLDOWN_MINUTES', 5),
    ],

    // Open board ("trip radar" fallback): a searching ride is also listed to every eligible online
    // driver once it has searched this long without an acceptance. 0 lists it at once (first to
    // claim wins, the fairness cost the research section describes). Set below 0 to switch it off.
    'open_board_after_seconds' => (int) env('EHAIL_OPEN_BOARD_AFTER_SECONDS', 45),

    // Driver waits this long at pickup before they may record a no-show.
    'no_show_wait_minutes' => (int) env('EHAIL_NO_SHOW_WAIT_MINUTES', 5),

    // Trip PIN (4 digits). Required to start a trip on rides that carry one.
    'pin' => [
        'required' => (bool) env('EHAIL_PIN_REQUIRED', true),
        'max_attempts' => (int) env('EHAIL_PIN_MAX_ATTEMPTS', 5),
        'lock_minutes' => (int) env('EHAIL_PIN_LOCK_MINUTES', 10),
    ],

    // Idempotency-Key responses are kept this long.
    'idempotency_hours' => (int) env('EHAIL_IDEMPOTENCY_HOURS', 24),

    'scheduled' => [
        // Earliest and latest a ride may be booked ahead.
        'min_lead_minutes' => (int) env('EHAIL_SCHEDULE_MIN_LEAD_MINUTES', 30),
        'max_lead_days' => (int) env('EHAIL_SCHEDULE_MAX_LEAD_DAYS', 30),
        // Dispatch starts this long before the pickup time.
        'allocation_lead_minutes' => (int) env('EHAIL_SCHEDULE_ALLOCATION_LEAD_MINUTES', 15),
        // Reminder event this long before pickup.
        'reminder_lead_minutes' => (int) env('EHAIL_SCHEDULE_REMINDER_LEAD_MINUTES', 60),
        // Price guarantee: the quoted fare is honoured if a fresh quote at dispatch time is within
        // this percentage; above it the passenger must accept the new fare before dispatch.
        'price_guarantee_percent' => (int) env('EHAIL_SCHEDULE_PRICE_GUARANTEE_PERCENT', 10),
        // Reschedule/cancel without the passenger-cancel fee up to this long before pickup.
        'free_change_until_minutes' => (int) env('EHAIL_SCHEDULE_FREE_CHANGE_MINUTES', 60),
        // A scheduled ride whose pickup time passed this long ago without dispatching expires.
        'grace_minutes' => (int) env('EHAIL_SCHEDULE_GRACE_MINUTES', 10),
    ],

    // Polling hints (seconds) sent as Retry-After.
    'poll' => [
        'passenger_active' => (int) env('EHAIL_POLL_PASSENGER_ACTIVE', 3),
        'passenger_idle' => (int) env('EHAIL_POLL_PASSENGER_IDLE', 30),
        'driver_online' => (int) env('EHAIL_POLL_DRIVER_ONLINE', 2),
        'driver_offline' => (int) env('EHAIL_POLL_DRIVER_OFFLINE', 30),
        // A poll also advances other due rides at most this often (bounded work per request).
        'global_tick_every_seconds' => (int) env('EHAIL_GLOBAL_TICK_EVERY_SECONDS', 2),
        'global_tick_batch' => (int) env('EHAIL_GLOBAL_TICK_BATCH', 5),
    ],

    // Stage the broadcastable variants of the domain events (for hosts that run Reverb).
    'broadcast_events' => (bool) env('EHAIL_BROADCAST_RIDE_EVENTS', false),

];
