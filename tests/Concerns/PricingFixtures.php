<?php

namespace Tests\Concerns;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Adapters\UnknownSupplyCounter;
use App\Domain\Pricing\Contracts\EligibleSupplyCounter;
use App\Domain\Pricing\Contracts\PricingRoutePlanner;
use App\Domain\Pricing\Contracts\PricingZoneLookup;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Models\CommissionPlan;
use App\Domain\Pricing\Models\PricingRuleSet;
use Illuminate\Support\Str;
use Tests\Doubles\Pricing\FakeRoutePlanner;

/**
 * Builders for pricing tests. Rule sets default to round numbers so expected fares are easy to read:
 * base R10, R8/km, R1/min, minimum R25, no booking fee, whole-cent rounding, ZA/ZAR, all categories.
 */
trait PricingFixtures
{
    /**
     * Pricing tests price trips without modelling the driver supply (runs automatically: Laravel calls
     * setUp<TraitName>). Since integration, EligibleSupplyCounter counts real online drivers (D-710), which a
     * pricing test does not create; supply-aware tests still pass their own counter through useSupply().
     */
    protected function setUpPricingFixtures(): void
    {
        $this->app->instance(EligibleSupplyCounter::class, new UnknownSupplyCounter);
    }

    protected function category(string $key = 'economy'): RideCategory
    {
        return RideCategory::query()->where('key', $key)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function publishedRuleSet(array $attributes = []): PricingRuleSet
    {
        return PricingRuleSet::query()->create(array_merge([
            'lineage_key' => (string) Str::ulid(),
            'version' => 1,
            'name' => 'Test tariff',
            'status' => 'published',
            'country' => 'ZA',
            'currency' => 'ZAR',
            'timezone' => 'Africa/Johannesburg',
            'base_minor' => 1000,
            'per_km_minor' => 800,
            'per_minute_minor' => 100,
            'minimum_minor' => 2500,
            'booking_fee_minor' => 0,
            'wait_free_minutes' => 5,
            'wait_per_minute_minor' => 200,
            'cancel_free_minutes' => 2,
            'cancel_fee_minor' => 2500,
            'no_show_fee_minor' => 3000,
            'toll_mode' => 'none',
            'demand_enabled' => false,
            'demand_cap_bp' => 10000,
            'tax_mode' => 'none',
            'tax_rate_bp' => 0,
            'rounding_unit_minor' => 1,
            'rounding_mode' => 'half_up',
            'distance_tolerance_bp' => 1500,
            'duration_tolerance_bp' => 2500,
            'overrun_cap_bp' => 2000,
            'effective_from' => now()->subDay(),
            'published_at' => now()->subDay(),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function publishedCommissionPlan(array $attributes = []): CommissionPlan
    {
        return CommissionPlan::query()->create(array_merge([
            'lineage_key' => (string) Str::ulid(),
            'version' => 1,
            'name' => 'Test commission',
            'status' => 'published',
            'country' => 'ZA',
            'currency' => 'ZAR',
            'percent_bp' => 2000,
            'fixed_minor' => 0,
            'effective_from' => now()->subDay(),
            'published_at' => now()->subDay(),
        ], $attributes));
    }

    protected function fakeRoute(int $distanceM = 10_000, int $durationS = 900): FakeRoutePlanner
    {
        $planner = new FakeRoutePlanner($distanceM, $durationS);
        $this->app->instance(PricingRoutePlanner::class, $planner);

        return $planner;
    }

    protected function useZones(PricingZoneLookup $zones): void
    {
        $this->app->instance(PricingZoneLookup::class, $zones);
    }

    protected function useSupply(EligibleSupplyCounter $supply): void
    {
        $this->app->instance(EligibleSupplyCounter::class, $supply);
    }

    protected function capeTown(): GeoPoint
    {
        return new GeoPoint(-33.9249, 18.4241);
    }

    protected function seaPoint(): GeoPoint
    {
        return new GeoPoint(-33.9150, 18.3870);
    }
}
