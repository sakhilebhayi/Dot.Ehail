<?php

namespace Tests\Unit\Pricing;

use App\Domain\Pricing\Data\ZoneRef;
use App\Domain\Pricing\Engine\DiscountTerms;
use App\Domain\Pricing\Engine\FareBreakdown;
use App\Domain\Pricing\Engine\FareEngine;
use App\Domain\Pricing\Engine\FareInputs;
use App\Domain\Pricing\Models\PricingRuleSet;
use Tests\TestCase;

class FareEngineTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function rules(array $overrides = []): PricingRuleSet
    {
        return new PricingRuleSet(array_merge([
            'name' => 't', 'status' => 'published', 'version' => 1, 'country' => 'ZA', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
            'base_minor' => 1000, 'per_km_minor' => 800, 'per_minute_minor' => 100, 'minimum_minor' => 2500, 'booking_fee_minor' => 0,
            'wait_free_minutes' => 5, 'wait_per_minute_minor' => 200, 'cancel_free_minutes' => 2, 'cancel_fee_minor' => 0, 'no_show_fee_minor' => 0,
            'zone_fees' => null, 'toll_mode' => 'none', 'demand_enabled' => false, 'demand_cap_bp' => 10000, 'tax_mode' => 'none', 'tax_rate_bp' => 0,
            'rounding_unit_minor' => 1, 'rounding_mode' => 'half_up', 'distance_tolerance_bp' => 1500, 'duration_tolerance_bp' => 2500, 'overrun_cap_bp' => 2000,
        ], $overrides));
    }

    private function compute(array $rules = [], ...$inputs): FareBreakdown
    {
        return (new FareEngine)->compute($this->rules($rules), new FareInputs(...$inputs));
    }

    /**
     * @return array<string, int>
     */
    private function amounts(FareBreakdown $b): array
    {
        $out = [];

        foreach ($b->components as $c) {
            $out[$c->code] = $c->amountMinor;
        }

        return $out;
    }

    public function test_base_distance_and_time(): void
    {
        $b = $this->compute([], distanceM: 10_000, durationS: 900);

        $this->assertSame(['base' => 1000, 'distance' => 8000, 'time' => 1500], $this->amounts($b));
        $this->assertSame(10_500, $b->totalMinor());
        $this->assertSame('10 km x R8.00/km', $b->components[1]->detail);
        $this->assertSame('15 min x R1.00/min', $b->components[2]->detail);
    }

    public function test_distance_is_per_1000_metres_with_rounding_of_partial_kilometres(): void
    {
        // D-1900: distance is billed in tenths of a km (half up) and the line is rate x that quantity.
        // 1 234 m -> 1.2 km x R8/km = 960 cents; 1 250 m -> 1.3 km -> 1 040
        $this->assertSame(960, $this->compute([], distanceM: 1234, durationS: 0)->amountFor('distance'));
        $this->assertSame(1040, $this->compute([], distanceM: 1250, durationS: 0)->amountFor('distance'));
        // 1.2 km x R7.55/km = 906 cents exactly; 1.3 km x R7.55 = 981.5 -> 982 (half up)
        $this->assertSame(906, $this->compute(['per_km_minor' => 755], distanceM: 1200, durationS: 0)->amountFor('distance'));
        $this->assertSame(982, $this->compute(['per_km_minor' => 755], distanceM: 1300, durationS: 0)->amountFor('distance'));
        // under 50 m bills nothing
        $this->assertSame(0, $this->compute(['per_km_minor' => 100], distanceM: 49, durationS: 0)->amountFor('distance'));
    }

    public function test_minimum_fare_is_a_visible_line(): void
    {
        $b = $this->compute([], distanceM: 500, durationS: 60);

        // 1000 + 400 + 100 = 1500 < 2500
        $this->assertSame(1000, $b->amountFor('minimum_adjustment'));
        $this->assertSame(2500, $b->totalMinor());
    }

    public function test_minimum_not_applied_when_fare_is_above_it(): void
    {
        $this->assertSame(0, $this->compute([], distanceM: 20_000, durationS: 600)->amountFor('minimum_adjustment'));
    }

    public function test_booking_fee_counts_toward_the_minimum(): void
    {
        $b = $this->compute(['booking_fee_minor' => 500], distanceM: 500, durationS: 60);

        $this->assertSame(500, $b->amountFor('booking_fee'));
        $this->assertSame(500, $b->amountFor('minimum_adjustment'));
        $this->assertSame(2500, $b->totalMinor());
    }

    public function test_waiting_charges_each_started_minute_after_the_free_window(): void
    {
        $this->assertSame(0, $this->compute([], distanceM: 10_000, durationS: 900, waitingSeconds: 300)->amountFor('waiting'));
        $this->assertSame(200, $this->compute([], distanceM: 10_000, durationS: 900, waitingSeconds: 301)->amountFor('waiting'));
        $this->assertSame(1200, $this->compute([], distanceM: 10_000, durationS: 900, waitingSeconds: 660)->amountFor('waiting'));
        $this->assertSame(1, FareEngine::billableWaitMinutes(301, 5));
        $this->assertSame(6, FareEngine::billableWaitMinutes(660, 5));
    }

    public function test_zone_fees_apply_once_per_fee_code_and_end(): void
    {
        $rules = ['zone_fees' => ['airport' => ['pickup_minor' => 2000, 'dropoff_minor' => 500, 'label' => 'Airport fee']]];
        $airport = new ZoneRef(1, 'cpt-airport', 'airport', 'airport');
        $airportOverlap = new ZoneRef(2, 'cpt-airport-terminal', 'airport', 'airport');
        $special = new ZoneRef(3, 'stadium', 'special', null);

        $b = $this->compute($rules, distanceM: 10_000, durationS: 900, pickupZones: [$airport, $airportOverlap, $special], dropoffZones: [$airport]);

        $this->assertSame(2000, $b->amountFor('zone_fee:airport:pickup'));
        $this->assertSame(500, $b->amountFor('zone_fee:airport:dropoff'));
        $this->assertSame(2500, $b->amountFor('zone_fee'));
        $this->assertSame('Airport fee', $b->components[3]->label);
    }

    public function test_zone_without_a_configured_fee_costs_nothing(): void
    {
        $b = $this->compute([], distanceM: 10_000, durationS: 900, pickupZones: [new ZoneRef(1, 'x', 'airport', 'airport')]);
        $this->assertSame(0, $b->amountFor('zone_fee'));
    }

    public function test_tolls_pass_through_estimate_actual_and_unknown(): void
    {
        $estimate = $this->compute(['toll_mode' => 'pass_through'], distanceM: 10_000, durationS: 900, tollsMinor: 4300);
        $this->assertSame(4300, $estimate->amountFor('tolls'));
        $this->assertSame('Tolls (estimate)', $estimate->components[3]->label);

        $unknown = $this->compute(['toll_mode' => 'pass_through'], distanceM: 10_000, durationS: 900);
        $this->assertSame(0, $unknown->amountFor('tolls'));
        $this->assertContains('Tolls on the route, if any, are added at cost after the trip.', $unknown->notes);

        $none = $this->compute(['toll_mode' => 'none'], distanceM: 10_000, durationS: 900, tollsMinor: 4300);
        $this->assertSame(0, $none->amountFor('tolls'));
    }

    public function test_demand_only_when_enabled_capped_shown_and_never_on_tolls(): void
    {
        $off = $this->compute(['demand_enabled' => false, 'demand_cap_bp' => 20000], distanceM: 10_000, durationS: 900, demandMultiplierBp: 15000);
        $this->assertSame(0, $off->amountFor('demand'));
        $this->assertNull($off->toArray()['demand_multiplier']);

        $capped = $this->compute(['demand_enabled' => true, 'demand_cap_bp' => 13000, 'toll_mode' => 'pass_through'], distanceM: 10_000, durationS: 900, demandMultiplierBp: 25000, tollsMinor: 1000);
        // trip 10 500 x 0.3 = 3 150 (tolls excluded)
        $this->assertSame(3150, $capped->amountFor('demand'));
        $this->assertSame(13000, $capped->demandMultiplierBp);
        $this->assertSame('1.3000x', $capped->toArray()['demand_multiplier']);

        $below = $this->compute(['demand_enabled' => true, 'demand_cap_bp' => 13000], distanceM: 10_000, durationS: 900, demandMultiplierBp: 8000);
        $this->assertSame(0, $below->amountFor('demand'));
    }

    public function test_exclusive_tax_is_added_inclusive_tax_is_only_shown(): void
    {
        $exclusive = $this->compute(['tax_mode' => 'exclusive', 'tax_rate_bp' => 1500, 'tax_label' => 'VAT'], distanceM: 10_000, durationS: 900);
        $this->assertSame(1575, $exclusive->amountFor('tax'));
        $this->assertSame(12_075, $exclusive->totalMinor());

        $inclusive = $this->compute(['tax_mode' => 'inclusive', 'tax_rate_bp' => 1500, 'tax_label' => 'VAT'], distanceM: 10_000, durationS: 900);
        $this->assertSame(0, $inclusive->amountFor('tax'));
        $this->assertSame(10_500, $inclusive->totalMinor());
        // 10 500 x 15 / 115 = 1 369.56 -> 1 370
        $this->assertSame(1370, $inclusive->taxIncludedMinor);
        $this->assertSame('Includes VAT of R13.70.', $inclusive->notes[0]);

        $none = $this->compute(['tax_mode' => 'none', 'tax_rate_bp' => 1500], distanceM: 10_000, durationS: 900);
        $this->assertSame(0, $none->amountFor('tax'));
        $this->assertSame(0, $none->taxIncludedMinor);
    }

    public function test_rounding_to_whole_rand_is_a_visible_line(): void
    {
        $b = $this->compute(['rounding_unit_minor' => 100], distanceM: 1234, durationS: 650);
        // 1000 + 1.2 km x 800 (960) + 10.8 min x 100 (1080) = 3040 -> 3000
        $this->assertSame(-40, $b->amountFor('rounding'));
        $this->assertSame(3000, $b->totalMinor());

        $down = $this->compute(['rounding_unit_minor' => 100, 'rounding_mode' => 'down'], distanceM: 1234, durationS: 650);
        $this->assertSame(3000, $down->totalMinor());
    }

    public function test_percent_promotion_with_cap_and_fixed_promotion_never_below_zero(): void
    {
        $percent = $this->compute([], distanceM: 10_000, durationS: 900, discount: new DiscountTerms('Launch', 5000, null, 2000));
        $this->assertSame(-2000, $percent->amountFor('promotion'));
        $this->assertSame(8500, $percent->totalMinor());

        $huge = $this->compute([], distanceM: 10_000, durationS: 900, discount: DiscountTerms::fixed('Gift', 1_000_000));
        $this->assertSame(-10_500, $huge->amountFor('promotion'));
        $this->assertSame(0, $huge->totalMinor());
    }

    public function test_promotion_is_applied_before_exclusive_tax(): void
    {
        $b = $this->compute(['tax_mode' => 'exclusive', 'tax_rate_bp' => 1500], distanceM: 10_000, durationS: 900, discount: DiscountTerms::fixed('R5 off', 500));
        $this->assertSame(1500, $b->amountFor('tax'));
        $this->assertSame(11_500, $b->totalMinor());
    }

    public function test_property_total_equals_sum_never_negative_deterministic(): void
    {
        mt_srand(20261110);

        for ($i = 0; $i < 400; $i++) {
            $rules = [
                'base_minor' => mt_rand(0, 5000), 'per_km_minor' => mt_rand(0, 3000), 'per_minute_minor' => mt_rand(0, 500),
                'minimum_minor' => mt_rand(0, 20000), 'booking_fee_minor' => mt_rand(0, 1000), 'wait_free_minutes' => mt_rand(0, 10),
                'wait_per_minute_minor' => mt_rand(0, 500), 'toll_mode' => mt_rand(0, 1) ? 'pass_through' : 'none',
                'demand_enabled' => (bool) mt_rand(0, 1), 'demand_cap_bp' => mt_rand(10000, 30000),
                'tax_mode' => ['none', 'inclusive', 'exclusive'][mt_rand(0, 2)], 'tax_rate_bp' => mt_rand(0, 2500),
                'rounding_unit_minor' => [1, 10, 50, 100, 500][mt_rand(0, 4)], 'rounding_mode' => ['half_up', 'half_even', 'up', 'down'][mt_rand(0, 3)],
                'zone_fees' => ['airport' => ['pickup_minor' => mt_rand(0, 5000), 'dropoff_minor' => mt_rand(0, 5000), 'label' => 'A']],
            ];
            $discount = match (mt_rand(0, 2)) {
                0 => null,
                1 => new DiscountTerms('P', mt_rand(0, 10000), null, mt_rand(0, 1) ? mt_rand(0, 20000) : null),
                default => DiscountTerms::fixed('F', mt_rand(0, 50000)),
            };
            $args = [
                'distanceM' => mt_rand(0, 300_000), 'durationS' => mt_rand(0, 14_400),
                'pickupZones' => mt_rand(0, 1) ? [new ZoneRef(1, 'a', 'airport', 'airport')] : [],
                'dropoffZones' => mt_rand(0, 1) ? [new ZoneRef(1, 'a', 'airport', 'airport')] : [],
                'demandMultiplierBp' => mt_rand(5000, 40000), 'tollsMinor' => mt_rand(0, 1) ? mt_rand(0, 20000) : null,
                'waitingSeconds' => mt_rand(0, 1800), 'discount' => $discount,
            ];

            $a = $this->compute($rules, ...$args);
            $b = $this->compute($rules, ...$args);

            $sum = array_sum(array_map(fn ($c) => $c->amountMinor, $a->components));
            $this->assertSame($sum, $a->totalMinor(), 'total must equal the sum of components');
            $this->assertGreaterThanOrEqual(0, $a->totalMinor(), 'a fare can never be negative');
            $this->assertEquals($a, $b, 'pricing is deterministic');
            $this->assertSame(0, $a->totalMinor() % $rules['rounding_unit_minor'], 'total respects the rounding unit');

            foreach ($a->components as $c) {
                $this->assertIsInt($c->amountMinor);

                if (! in_array($c->code, ['promotion', 'rounding'], true)) {
                    $this->assertGreaterThanOrEqual(0, $c->amountMinor, $c->code.' must not be negative');
                }
            }

            if ($discount === null && $rules['tax_mode'] !== 'exclusive') {
                $this->assertGreaterThanOrEqual($rules['minimum_minor'] - $rules['rounding_unit_minor'], $a->totalMinor(), 'minimum holds (within rounding)');
            }
        }
    }
}
