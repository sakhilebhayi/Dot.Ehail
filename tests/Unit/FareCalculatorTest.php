<?php

namespace Tests\Unit;

use App\Services\Ehail\FareCalculator;
use Tests\TestCase;

class FareCalculatorTest extends TestCase
{
    public function test_distance_between_identical_points_is_zero(): void
    {
        $calculator = new FareCalculator;

        $this->assertSame(0.0, $calculator->distanceKm(-33.9249, 18.4241, -33.9249, 18.4241));
    }

    public function test_distance_between_known_coordinates_is_approximately_correct(): void
    {
        $calculator = new FareCalculator;

        // Cape Town CBD to Cape Town International Airport, ~19km by air.
        $distance = $calculator->distanceKm(-33.9249, 18.4241, -33.9715, 18.6021);

        $this->assertEqualsWithDelta(19.0, $distance, 2.0);
    }

    public function test_estimate_includes_the_base_fare_even_for_zero_distance(): void
    {
        $calculator = new FareCalculator;

        $this->assertSame(25.0, $calculator->estimate(0, 'standard'));
    }

    public function test_estimate_scales_with_distance(): void
    {
        $calculator = new FareCalculator;

        $near = $calculator->estimate(5, 'standard');
        $far = $calculator->estimate(20, 'standard');

        $this->assertGreaterThan($near, $far);
    }

    public function test_premium_vehicle_type_costs_more_than_economy_for_the_same_distance(): void
    {
        $calculator = new FareCalculator;

        $economy = $calculator->estimate(10, 'economy');
        $premium = $calculator->estimate(10, 'premium');

        $this->assertGreaterThan($economy, $premium);
    }

    public function test_an_unknown_vehicle_type_falls_back_to_a_neutral_multiplier(): void
    {
        $calculator = new FareCalculator;

        $this->assertSame($calculator->estimate(10, 'economy'), $calculator->estimate(10, 'not-a-real-type'));
    }

    public function test_the_tariff_is_read_from_config_not_hardcoded(): void
    {
        config([
            'ehail.tariff.base_fare' => 40.0,
            'ehail.tariff.per_km' => 10.0,
            'ehail.tariff.multipliers' => ['standard' => 1.5],
        ]);

        // 40 + 10 km * 10 * 1.5
        $this->assertSame(190.0, (new FareCalculator)->estimate(10, 'standard'));
    }

    public function test_the_shipped_defaults_match_the_previous_hardcoded_tariff(): void
    {
        $this->assertSame(25.0, config('ehail.tariff.base_fare'));
        $this->assertSame(8.5, config('ehail.tariff.per_km'));
        $this->assertSame(
            ['economy' => 1.0, 'standard' => 1.2, 'premium' => 1.6, 'suv' => 1.8],
            config('ehail.tariff.multipliers'),
        );
    }

    public function test_money_is_formatted_with_the_configured_currency(): void
    {
        $this->assertSame('R 1,234.50', FareCalculator::format(1234.5));

        config(['ehail.currency.symbol' => '$']);
        $this->assertSame('$ 9.00', FareCalculator::format(9));
    }

    public function test_every_vehicle_type_has_a_multiplier_and_a_tier_mapping(): void
    {
        foreach (['economy', 'standard', 'premium', 'suv'] as $type) {
            $this->assertArrayHasKey($type, config('ehail.tariff.multipliers'));
            $this->assertContains($type, config("ehail.vehicle_tiers.{$type}"));
        }
    }
}
