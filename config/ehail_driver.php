<?php

/*
|--------------------------------------------------------------------------
| R-46 driver app (/drive): interface policies (docs/R46_UI_DRIVER.md)
|--------------------------------------------------------------------------
| These tune what the driver app shows and how often the phone reports. Business rules (who may go online,
| how offers move, what a trip pays) live in the dispatch, safety, pricing and money domains.
*/

return [

    // Times on the driver pages are shown in the market's local time (storage stays UTC).
    'timezone' => env('EHAIL_DRIVER_TIMEZONE', 'Africa/Johannesburg'),

    // "I've arrived" is enabled when the phone's last fix is within this distance of the pickup (plus the fix's
    // own accuracy, capped). Farther away the driver must confirm, and the confirmation is audited.
    'arrival' => [
        'tolerance_m' => (int) env('EHAIL_DRIVER_ARRIVAL_TOLERANCE_M', 250),
        'accuracy_allowance_cap_m' => (int) env('EHAIL_DRIVER_ARRIVAL_ACCURACY_CAP_M', 100),
        // A position older than this is not trusted for the check (the driver must confirm instead).
        'max_fix_age_s' => (int) env('EHAIL_DRIVER_ARRIVAL_MAX_FIX_AGE_S', 120),
    ],

    // What an offer shows of the destination before acceptance: 'area' (the last parts of the address, such as
    // suburb and city) or 'address' (the full address). The full address is always shown after acceptance.
    'offer_destination_detail' => env('EHAIL_DRIVER_OFFER_DESTINATION', 'area'),

    // Phone reporting while online. Geo accepts one location post per 2 s; dispatch one heartbeat per second.
    'location' => [
        'post_every_s' => (int) env('EHAIL_DRIVER_LOCATION_EVERY_S', 4),
        // Used when the phone asks to save data (Save-Data) or reports a low battery.
        'saver_post_every_s' => (int) env('EHAIL_DRIVER_LOCATION_SAVER_EVERY_S', 8),
        'heartbeat_every_s' => (int) env('EHAIL_DRIVER_HEARTBEAT_EVERY_S', 10),
        // Fixes queued while offline (a dead zone) are sent together, at most this many per post (geo limit 20).
        'max_batch' => 20,
    ],

    // Minimal-distraction mode: entered above enter_kmh (two fixes in a row), left below exit_kmh for exit_after_s.
    'moving' => [
        'enter_kmh' => (float) env('EHAIL_DRIVER_MOVING_ENTER_KMH', 10),
        'exit_kmh' => (float) env('EHAIL_DRIVER_MOVING_EXIT_KMH', 5),
        'exit_after_s' => (int) env('EHAIL_DRIVER_MOVING_EXIT_AFTER_S', 10),
    ],
];
