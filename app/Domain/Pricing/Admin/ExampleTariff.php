<?php

namespace App\Domain\Pricing\Admin;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Catalog\RideCategoryCatalogue;
use App\Domain\Pricing\Models\CommissionPlan;
use App\Domain\Pricing\Models\PricingRuleSet;
use Illuminate\Support\Facades\DB;

/**
 * EXAMPLE South African tariff: one published rule set per active category plus one example commission plan,
 * country-wide (service_area_id null, so it covers every seeded service area). Every row is is_example = true and
 * named "Example tariff: review before launch". The numbers are placeholders for development and acceptance tests,
 * NOT researched or approved prices: the owner sets real tariffs (docs/R46_PRICING.md, owner decisions).
 *
 * VAT is off (tax_mode none) until the owner's accountant confirms the operator's VAT position.
 */
final class ExampleTariff
{
    public const NAME = 'Example tariff: review before launch';

    /**
     * category key => [base, per_km, per_minute, minimum, booking_fee, wait_per_minute, cancel_fee, no_show_fee] in cents
     *
     * @var array<string, list<int>>
     */
    public const VALUES = [
        'economy' => [1000, 750, 70, 3000, 300, 150, 2500, 3000],
        'standard' => [1500, 950, 90, 4000, 300, 150, 2500, 3500],
        'premium' => [2500, 1400, 150, 7000, 500, 250, 4000, 5000],
        'xl' => [2000, 1200, 120, 5500, 400, 200, 3000, 4000],
        // Accessible rides cost the same as Economy on purpose: no surcharge for needing an accessible vehicle.
        'accessible' => [1000, 750, 70, 3000, 300, 150, 2500, 3000],
        'business' => [2500, 1400, 150, 7000, 500, 250, 4000, 5000],
        'airport' => [2000, 950, 80, 15000, 300, 150, 3000, 5000],
        'long_distance' => [3000, 850, 30, 30000, 500, 150, 5000, 8000],
    ];

    /**
     * @return array{rule_sets: int, commission_plans: int}
     */
    public static function install(): array
    {
        RideCategoryCatalogue::install();
        $created = ['rule_sets' => 0, 'commission_plans' => 0];

        DB::transaction(function () use (&$created) {
            $now = now();

            foreach (self::VALUES as $key => [$base, $perKm, $perMinute, $minimum, $bookingFee, $waitPerMinute, $cancelFee, $noShowFee]) {
                $category = RideCategory::query()->where('key', $key)->first();
                $lineage = substr('EXZA'.strtoupper($key), 0, 26);

                if ($category === null || PricingRuleSet::query()->where('lineage_key', $lineage)->exists()) {
                    continue;
                }

                PricingRuleSet::query()->create([
                    'lineage_key' => $lineage,
                    'version' => 1,
                    'name' => self::NAME.' ('.$category->name.')',
                    'notes' => 'Placeholder values shipped with the code. Replace with owner-approved prices before launch.',
                    'is_example' => true,
                    'status' => 'published',
                    'country' => 'ZA',
                    'currency' => 'ZAR',
                    'service_area_id' => null,
                    'zone_slug' => null,
                    'ride_category_id' => $category->id,
                    'time_windows' => null,
                    'timezone' => 'Africa/Johannesburg',
                    'base_minor' => $base,
                    'per_km_minor' => $perKm,
                    'per_minute_minor' => $perMinute,
                    'minimum_minor' => $minimum,
                    'booking_fee_minor' => $bookingFee,
                    'wait_free_minutes' => 5,
                    'wait_per_minute_minor' => $waitPerMinute,
                    'cancel_free_minutes' => 2,
                    'cancel_fee_minor' => $cancelFee,
                    'no_show_fee_minor' => $noShowFee,
                    // No airport fee: none is documented by ACSA for e-hailing today (docs/R46_RESEARCH.md, Pricing).
                    'zone_fees' => null,
                    'toll_mode' => 'pass_through',
                    'demand_enabled' => false,
                    'demand_cap_bp' => 15000,
                    'tax_mode' => 'none',
                    'tax_rate_bp' => 0,
                    'tax_label' => null,
                    'rounding_unit_minor' => 100,
                    'rounding_mode' => 'half_up',
                    'distance_tolerance_bp' => 1500,
                    'duration_tolerance_bp' => 2500,
                    'overrun_cap_bp' => 2000,
                    'effective_from' => $now,
                    'published_at' => $now,
                ]);
                $created['rule_sets']++;
            }

            if (! CommissionPlan::query()->where('lineage_key', 'EXZACOMMISSION')->exists()) {
                CommissionPlan::query()->create([
                    'lineage_key' => 'EXZACOMMISSION',
                    'version' => 1,
                    'name' => 'Example commission: review before launch',
                    'is_example' => true,
                    'status' => 'published',
                    'country' => 'ZA',
                    'currency' => 'ZAR',
                    'percent_bp' => 2000,
                    'fixed_minor' => 0,
                    'effective_from' => $now,
                    'published_at' => $now,
                ]);
                $created['commission_plans']++;
            }
        });

        return $created;
    }
}
