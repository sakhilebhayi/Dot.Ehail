<?php

namespace Tests\Unit;

use App\Services\Ehail\FareCalculator;
use Tests\TestCase;

class FareCalculatorTest extends TestCase
{
    /**
     * R-46 (D-4): the straight-line estimate and the haversine distance it billed on were removed; fares come only
     * from App\Domain\Pricing on road routes. The tests that exercised them were replaced by this guard and by
     * tests/Unit/Pricing and tests/Feature/Pricing.
     */
    public function test_straight_line_pricing_no_longer_exists(): void
    {
        $this->assertFalse(method_exists(FareCalculator::class, 'estimate'));
        $this->assertFalse(method_exists(FareCalculator::class, 'distanceKm'));
    }

    public function test_cents_and_legacy_decimals_format_identically(): void
    {
        $this->assertSame('R80.00', FareCalculator::format('80.00'));
        $this->assertSame('R80.00', FareCalculator::formatCents(8000));
        $this->assertSame('R0.05', FareCalculator::formatCents(5));
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
        $this->assertSame("R1\u{202F}234.50", FareCalculator::format(1234.5));

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
