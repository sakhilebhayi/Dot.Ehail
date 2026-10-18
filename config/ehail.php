<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    | Display only. No payment provider is integrated: every amount in this
    | application is an estimate or a recorded fare, never a collected one.
    */
    'currency' => [
        'code' => env('EHAIL_CURRENCY_CODE', 'ZAR'),
        'symbol' => env('EHAIL_CURRENCY_SYMBOL', 'R'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tariff
    |--------------------------------------------------------------------------
    | estimate = base_fare + distance_km * per_km * multiplier[vehicle tier].
    | The base fare is flat; the tier multiplies only the metered part.
    */
    'tariff' => [
        'base_fare' => (float) env('EHAIL_BASE_FARE', 25.0),
        'per_km' => (float) env('EHAIL_PER_KM', 8.5),
        'multipliers' => [
            'economy' => 1.0,
            'standard' => 1.2,
            'premium' => 1.6,
            'suv' => 1.8,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vehicle tiers
    |--------------------------------------------------------------------------
    | Requested tier => vehicle types that may serve it. Strict by default (a
    | premium ride is only accepted in a premium vehicle). To let a higher
    | class serve a lower one, add it to the lower tier's list, e.g.
    | 'standard' => ['standard', 'premium'].
    */
    'vehicle_tiers' => [
        'economy' => ['economy'],
        'standard' => ['standard'],
        'premium' => ['premium'],
        'suv' => ['suv'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ride housekeeping (php artisan ehail:expire-rides, every minute)
    |--------------------------------------------------------------------------
    | ride_expiry_minutes: a ride still 'requested' this long after it entered
    |   the queue is expired and the passenger is told.
    | stuck_ride_hours: a trip 'in_progress' this long is flagged
    |   'needs_review' for an operator. It is never completed automatically.
    | max_reassignments: how many times a driver cancelling after accepting may
    |   send the ride back to the queue before it expires instead.
    */
    'ride_expiry_minutes' => (int) env('EHAIL_RIDE_EXPIRY_MINUTES', 10),
    'stuck_ride_hours' => (int) env('EHAIL_STUCK_RIDE_HOURS', 6),
    'max_reassignments' => (int) env('EHAIL_MAX_REASSIGNMENTS', 3),

];
