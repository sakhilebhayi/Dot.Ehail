<?php

namespace Tests\Feature\Pricing;

use App\Domain\Catalog\Models\CategoryVehicleRule;
use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Catalog\RideCategoryCatalogue;
use App\Domain\Catalog\Services\CategoryAvailability;
use App\Domain\Catalog\Services\CategoryEligibility;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Data\ServiceAreaRef;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Quotes\FareQuoter;
use App\Domain\Pricing\Quotes\QuoteRequest;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\PricingFixtures;
use Tests\Doubles\Pricing\FakeSupplyCounter;
use Tests\Doubles\Pricing\FakeZoneLookup;
use Tests\TestCase;

class PromotionAndCatalogTest extends TestCase
{
    use CreatesEhailFixtures, PricingFixtures, RefreshDatabase;

    private function promo(array $attributes): Promotion
    {
        return Promotion::query()->create(array_merge(['name' => 'Promo', 'kind' => 'code', 'discount_type' => 'fixed', 'amount_minor' => 1000, 'currency' => 'ZAR', 'per_user_limit' => 1], $attributes));
    }

    private function priced(?User $passenger, ?string $code, string $category = 'economy', ?CarbonImmutable $at = null)
    {
        return $this->app->make(FareQuoter::class)->quote(new QuoteRequest($this->capeTown(), $this->seaPoint(), $category, $passenger, $at, $code));
    }

    public function test_promotion_eligibility_rules(): void
    {
        // Pin the clock: the quotes below are at fixed instants on 2026-11-10, and fixture rule sets are effective from
        // now() - 1 day, so an unpinned clock makes them "not yet effective" on any real date after 2026-11-11.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Africa/Johannesburg'));

        $this->publishedRuleSet();
        $this->fakeRoute();
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->promo(['code' => 'premium-only', 'eligibility' => ['category_keys' => ['premium']]]);
        $this->promo(['code' => 'later', 'starts_at' => now()->addDay()]);
        $this->promo(['code' => 'over', 'ends_at' => now()->subMinute()]);
        $this->promo(['code' => 'off', 'is_active' => false]);
        $this->promo(['code' => 'usd', 'currency' => 'USD']);
        $this->promo(['code' => 'big', 'eligibility' => ['min_fare_minor' => 50_000]]);
        $this->promo(['code' => 'lunch', 'eligibility' => ['time_windows' => [['start' => '12:00', 'end' => '14:00']]]]);
        $this->promo(['code' => 'area', 'eligibility' => ['service_area_ids' => [42]]]);
        $this->promo(['code' => 'spent', 'budget_minor' => 1000, 'spent_minor' => 0])->forceFill(['spent_minor' => 1000])->save();

        $expect = [
            'nope' => 'not valid', 'premium-only' => 'does not apply to Economy', 'later' => 'not started', 'over' => 'ended',
            'off' => 'ended', 'usd' => 'not valid in this market', 'big' => 'higher fare', 'area' => 'does not apply in this area', 'spent' => 'fully used',
        ];

        foreach ($expect as $code => $fragment) {
            $option = $this->priced($passenger, $code);
            $this->assertSame(0, $option->breakdown->amountFor('promotion'), $code);
            $this->assertStringContainsString($fragment, (string) $option->promotionNote, $code);
        }

        $lunch = $this->priced($passenger, 'LUNCH', 'economy', CarbonImmutable::parse('2026-11-10 13:00', 'Africa/Johannesburg'));
        $this->assertSame(-1000, $lunch->breakdown->amountFor('promotion'), 'case-insensitive code inside its window');
        $this->assertStringContainsString('at this time', (string) $this->priced($passenger, 'lunch', 'economy', CarbonImmutable::parse('2026-11-10 15:00', 'Africa/Johannesburg'))->promotionNote);
    }

    public function test_first_ride_only_promotion(): void
    {
        $this->publishedRuleSet();
        $this->fakeRoute();
        $this->promo(['code' => 'FIRST', 'eligibility' => ['first_ride_only' => true]]);
        $newcomer = User::factory()->withPersonalTeam()->create();
        $regular = User::factory()->withPersonalTeam()->create();
        $this->makeRide(['status' => 'completed', 'final_fare' => 50], $regular);

        $this->assertSame(-1000, $this->priced($newcomer, 'FIRST')->breakdown->amountFor('promotion'));
        $this->assertStringContainsString('first ride', (string) $this->priced($regular, 'FIRST')->promotionNote);
    }

    public function test_best_automatic_promotion_applies_and_anonymous_estimates_get_none(): void
    {
        $this->publishedRuleSet();
        $this->fakeRoute();
        $this->promo(['code' => null, 'kind' => 'automatic', 'name' => 'Small', 'amount_minor' => 500]);
        $this->promo(['code' => null, 'kind' => 'automatic', 'name' => 'Ten percent capped', 'discount_type' => 'percent', 'percent_bp' => 1000, 'amount_minor' => null, 'max_discount_minor' => 800]);
        $passenger = User::factory()->withPersonalTeam()->create();

        $option = $this->priced($passenger, null);
        $this->assertSame(-800, $option->breakdown->amountFor('promotion'));
        $this->assertSame('Ten percent capped', $option->promotion->name);

        $estimate = $this->app->make(FareQuoter::class)->estimate(new QuoteRequest($this->capeTown(), $this->seaPoint(), 'economy'));
        $this->assertSame(0, $estimate->breakdown->amountFor('promotion'));
    }

    public function test_budget_remaining_caps_the_discount(): void
    {
        $this->publishedRuleSet();
        $this->fakeRoute();
        $promotion = $this->promo(['code' => 'TAIL', 'amount_minor' => 1000, 'budget_minor' => 1300]);
        $promotion->forceFill(['spent_minor' => 1000])->save();

        $this->assertSame(-300, $this->priced(User::factory()->create(), 'TAIL')->breakdown->amountFor('promotion'));
    }

    public function test_catalogue_is_installed_by_migration_with_parcel_and_business_inactive(): void
    {
        $keys = RideCategory::query()->orderBy('display_order')->pluck('is_active', 'key')->all();

        $this->assertSame(['economy', 'standard', 'premium', 'xl', 'accessible', 'business', 'airport', 'long_distance', 'parcel'], array_keys($keys));
        $this->assertFalse($keys['parcel']);
        $this->assertFalse($keys['business']);
        $this->assertSame('parcel', RideCategory::where('key', 'parcel')->value('service_kind'));
        $this->assertSame(0, RideCategoryCatalogue::install(), 'install is idempotent');
        $this->assertSame('xl', RideCategoryCatalogue::forLegacyVehicleType('suv')->key);
    }

    public function test_install_never_overwrites_operator_edits(): void
    {
        RideCategory::where('key', 'economy')->update(['name' => 'Lite']);
        RideCategoryCatalogue::install();

        $this->assertSame('Lite', RideCategory::where('key', 'economy')->value('name'));
    }

    public function test_availability_only_lists_offered_priced_categories_with_supply(): void
    {
        // Pin the clock: the quotes below are at fixed instants on 2026-11-10, and fixture rule sets are effective from
        // now() - 1 day, so an unpinned clock makes them "not yet effective" on any real date after 2026-11-11.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Africa/Johannesburg'));

        $this->publishedRuleSet(['ride_category_id' => $this->category('economy')->id]);
        $this->publishedRuleSet(['ride_category_id' => $this->category('premium')->id]);
        $this->publishedRuleSet(['ride_category_id' => $this->category('xl')->id]);
        $this->category('xl')->update(['availability' => ['windows' => [['start' => '06:00', 'end' => '20:00']]]]);

        $offers = $this->app->make(CategoryAvailability::class)->availableAt($this->capeTown(), CarbonImmutable::parse('2026-11-10 10:00', 'Africa/Johannesburg'));
        $this->assertSame(['economy', 'premium', 'xl'], array_map(fn ($o) => $o->category->key, $offers));
        $this->assertSame('unknown', $offers[0]->supply, 'without the dispatch counter supply is unknown, not claimed');

        $night = $this->app->make(CategoryAvailability::class)->availableAt($this->capeTown(), CarbonImmutable::parse('2026-11-10 22:00', 'Africa/Johannesburg'));
        $this->assertSame(['economy', 'premium'], array_map(fn ($o) => $o->category->key, $night));

        $this->useSupply(new FakeSupplyCounter(['economy' => 3, 'premium' => 0]));
        $counted = $this->app->make(CategoryAvailability::class)->availableAt($this->capeTown(), CarbonImmutable::parse('2026-11-10 10:00', 'Africa/Johannesburg'));
        $this->assertSame(['economy', 'xl'], array_map(fn ($o) => $o->category->key, $counted));
        $this->assertSame('available', $counted[0]->supply);
        $this->assertSame(3, $counted[0]->eligibleDrivers);
    }

    public function test_availability_respects_service_area_lists(): void
    {
        $this->publishedRuleSet();
        $this->category('airport')->update(['availability' => ['service_area_ids' => [2]]]);
        $zones = (new FakeZoneLookup)->addArea(new ServiceAreaRef(1, 'cpt', 'ZA', 'ZAR', 'Africa/Johannesburg'), $this->capeTown(), 50_000);
        $this->useZones($zones);

        $keys = array_map(fn ($o) => $o->category->key, $this->app->make(CategoryAvailability::class)->availableAt($this->capeTown()));
        $this->assertNotContains('airport', $keys);
        $this->assertContains('economy', $keys);

        $this->assertSame([], $this->app->make(CategoryAvailability::class)->availableAt(new GeoPoint(-26.2, 28.04)), 'outside every area');
    }

    public function test_vehicle_and_driver_eligibility(): void
    {
        $eligibility = $this->app->make(CategoryEligibility::class);
        $driver = $this->makeApprovedDriver('suv');
        $vehicle = Vehicle::query()->firstOrFail();
        $vehicle->forceFill(['seats' => 6, 'year' => (int) now()->year - 3])->save();
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();

        $this->assertTrue($eligibility->vehicleQualifies($this->category('xl'), $vehicle));
        $this->assertSame(['vehicle_class'], $eligibility->vehicleFailures($this->category('premium'), $vehicle));
        $this->assertContains('missing_feature:wheelchair_access', $eligibility->vehicleFailures($this->category('accessible'), $vehicle), 'features are not recorded on vehicles yet, so nobody qualifies by assumption');

        $vehicle->forceFill(['seats' => 4])->save();
        $this->assertSame(['too_few_seats'], $eligibility->vehicleFailures($this->category('xl'), $vehicle->fresh()));
        $vehicle->forceFill(['seats' => 6, 'year' => 2005])->save();
        $this->assertSame(['too_old'], $eligibility->vehicleFailures($this->category('xl'), $vehicle->fresh()));

        $profile->forceFill(['rating' => 4.4, 'total_rides' => 20])->save();
        $this->assertSame(['rating_below_floor'], $eligibility->driverFailures($this->category('standard'), $profile->fresh()));
        $this->assertSame([], $eligibility->driverFailures($this->category('economy'), $profile->fresh()));
        $profile->forceFill(['rating' => 0, 'total_rides' => 0])->save();
        $this->assertSame([], $eligibility->driverFailures($this->category('standard'), $profile->fresh()), 'unrated new drivers are not held to the floor');

        $economy = $this->category('economy');
        $economy->update(['fleet_only' => true]);
        Fleet::query()->whereKey($profile->fleet_id)->update(['status' => 'pending']);
        $this->assertContains('fleet_only', $eligibility->driverFailures($economy->fresh(), $profile->fresh()));
        Fleet::query()->whereKey($profile->fleet_id)->update(['status' => 'approved']);
        $this->assertNotContains('fleet_only', $eligibility->driverFailures($economy->fresh(), $profile->fresh()));

        $this->assertContains('xl', $eligibility->servableCategoryKeys($vehicle->fresh()->forceFill(['year' => (int) now()->year])));
        $this->assertNotNull(CategoryVehicleRule::query()->first());
    }
}
