<?php

/*
|--------------------------------------------------------------------------
| R-46 pricing engine (App\Domain\Pricing, docs/R46_PRICING.md)
|--------------------------------------------------------------------------
| Prices themselves are NOT here: they live in versioned, published rule sets (pricing_rule_sets) that an operator
| manages. This file holds only engine behaviour and defaults for newly drafted rule sets.
*/

return [

    // Used when no service area resolves a point (until the geo zone lookup is wired) and for new rule sets.
    'default_country' => env('EHAIL_PRICING_COUNTRY', 'ZA'),
    'default_currency' => env('EHAIL_PRICING_CURRENCY', env('EHAIL_CURRENCY_CODE', 'ZAR')),
    'default_timezone' => env('EHAIL_PRICING_TIMEZONE', 'Africa/Johannesburg'),

    // ISO 4217 code => symbol and exponent (minor digits). Amounts are integers in minor units everywhere.
    'currencies' => [
        'ZAR' => ['symbol' => env('EHAIL_CURRENCY_SYMBOL', 'R'), 'exponent' => 2],
        'BWP' => ['symbol' => 'P', 'exponent' => 2],
        'NAD' => ['symbol' => 'N$', 'exponent' => 2],
        'KES' => ['symbol' => 'KSh', 'exponent' => 2],
        'NGN' => ['symbol' => '₦', 'exponent' => 2],
        'USD' => ['symbol' => '$', 'exponent' => 2],
    ],

    'quotes' => [
        // A booking quote is honoured for this long; after that the passenger must ask again.
        'ttl_seconds' => (int) env('EHAIL_QUOTE_TTL_SECONDS', 300),
        // A booking whose pickup or drop-off is further than this from the quoted points is refused (re-quote).
        'route_match_tolerance_m' => (int) env('EHAIL_QUOTE_ROUTE_MATCH_M', 75),
        // Unused booking quotes (they hold coordinates) are deleted after this many hours (POPIA minimisation).
        'retain_unconsumed_hours' => (int) env('EHAIL_QUOTE_RETAIN_HOURS', 24),
    ],

    // Per-minute request budgets (limiters are defined in EhailPricingServiceProvider::boot()).
    'rate_limits' => [
        'public_estimate_per_minute' => (int) env('EHAIL_ESTIMATE_PER_MINUTE', 20),
        'quote_per_minute' => (int) env('EHAIL_QUOTE_PER_MINUTE', 30),
    ],

    // Defaults copied into a NEW draft rule set; every published rule set carries its own values.
    'rule_set_defaults' => [
        'wait_free_minutes' => 5,
        'cancel_free_minutes' => 2,
        'distance_tolerance_bp' => 1500,
        'duration_tolerance_bp' => 2500,
        'overrun_cap_bp' => 2000,
        'demand_cap_bp' => 10000,
        'rounding_unit_minor' => 1,
        'rounding_mode' => 'half_up',
        'tax_mode' => 'none',
        'toll_mode' => 'none',
    ],

];
