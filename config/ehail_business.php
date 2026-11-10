<?php

/*
 * R-46 business accounts (docs/R46_BUSINESS.md). Every number here is a setting with a stated meaning,
 * not a market figure; credit limits and terms are set per account by an operator at approval.
 */
return [
    // Spend months, policy hours and statement periods are counted in this timezone.
    'timezone' => env('EHAIL_BUSINESS_TIMEZONE', 'Africa/Johannesburg'),

    // Countries a business account can be opened in, with the account currency.
    'countries' => [
        'ZA' => ['name' => 'South Africa', 'currency' => 'ZAR'],
    ],

    'invitations' => [
        'valid_days' => (int) env('EHAIL_BUSINESS_INVITE_DAYS', 14),
    ],

    'approvals' => [
        // An approved request must be booked within this many minutes (the traveller re-quotes, fares move).
        'use_within_minutes' => (int) env('EHAIL_BUSINESS_APPROVAL_USE_MINUTES', 120),
        // A booking matches an approval when the new fare is at most this much above the approved estimate
        // (basis points) and both stops are within this distance of the approved ones.
        'fare_tolerance_bp' => (int) env('EHAIL_BUSINESS_APPROVAL_FARE_TOLERANCE_BP', 1500),
        'stop_tolerance_m' => (int) env('EHAIL_BUSINESS_APPROVAL_STOP_TOLERANCE_M', 1000),
    ],

    // Guest phone numbers are kept for the driver only while needed, then wiped.
    'guest_phone_retention_days' => (int) env('EHAIL_BUSINESS_GUEST_PHONE_DAYS', 30),

    // A booked trip whose ride ended without any charge has its limits released after this many hours.
    'release_unbilled_after_hours' => (int) env('EHAIL_BUSINESS_RELEASE_HOURS', 24),

    // Statement numbers: <prefix>-<YYYYMM>-<account>.
    'statement_prefix' => env('EHAIL_BUSINESS_STATEMENT_PREFIX', 'EHB'),
];
