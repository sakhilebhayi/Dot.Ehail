<?php

namespace Tests\Feature\Pricing;

use App\Domain\Pricing\Contracts\DemandSignal;
use App\Domain\Pricing\Contracts\PricingRoutePlanner;
use App\Domain\Pricing\Contracts\TollEstimator;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Data\ServiceAreaRef;
use App\Domain\Pricing\Data\ZoneRef;
use App\Domain\Pricing\Exceptions\ImmutablePricingRecord;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Quotes\FareQuoter;
use App\Domain\Pricing\Quotes\PricedOption;
use App\Domain\Pricing\Quotes\QuoteComparison;
use App\Domain\Pricing\Quotes\QuoteRequest;
use App\Domain\Pricing\Quotes\QuoteSigner;
use App\Domain\Pricing\Quotes\QuoteUnavailable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PricingFixtures;
use Tests\Doubles\Pricing\FakeDemandSignal;
use Tests\Doubles\Pricing\FakeRoutePlanner;
use Tests\Doubles\Pricing\FakeSupplyCounter;
use Tests\Doubles\Pricing\FakeTollEstimator;
use Tests\Doubles\Pricing\FakeZoneLookup;
use Tests\TestCase;

class FareQuoterTest extends TestCase
{
    use PricingFixtures, RefreshDatabase;

    private function quoter(): FareQuoter
    {
        return $this->app->make(FareQuoter::class);
    }

    private function request(?string $category = 'economy', ?User $passenger = null, ?string $promo = null): QuoteRequest
    {
        return new QuoteRequest($this->capeTown(), $this->seaPoint(), $category, $passenger, null, $promo);
    }

    public function test_without_a_routing_provider_no_fare_is_produced(): void
    {
        $this->publishedRuleSet();

        $result = $this->quoter()->quote($this->request());

        $this->assertInstanceOf(QuoteUnavailable::class, $result);
        $this->assertSame('routing_unavailable', $result->reason);
        $this->assertSame('not_configured', $result->detail);
        // Since integration the geo RoutePlanner answers (driver "none"), with geo's operator-facing sentence.
        $this->assertStringContainsString('not configured a routing provider', $result->message);
        $this->assertSame(0, FareQuote::count());
    }

    public function test_routing_failure_is_reported_honestly(): void
    {
        $this->publishedRuleSet();
        $this->app->instance(PricingRoutePlanner::class, FakeRoutePlanner::failing('timeout'));

        $result = $this->quoter()->quote($this->request());

        $this->assertInstanceOf(QuoteUnavailable::class, $result);
        $this->assertSame('timeout', $result->detail);
    }

    public function test_a_signed_in_passenger_gets_a_persisted_signed_expiring_quote_priced_on_the_road_route(): void
    {
        $rules = $this->publishedRuleSet();
        $this->fakeRoute(12_345, 1_260);
        $passenger = User::factory()->create();

        $option = $this->quoter()->quote($this->request('economy', $passenger));

        $this->assertInstanceOf(PricedOption::class, $option);
        // 1000 + 12.345 km x 800 (9 876) + 21 min x 100 (2 100) = 12 976
        $this->assertSame(12_976, $option->breakdown->totalMinor());
        $quote = $option->quote;
        $this->assertNotNull($quote);
        $this->assertSame($passenger->id, $quote->passenger_id);
        $this->assertSame($rules->id, $quote->rule_set_id);
        $this->assertSame(1, $quote->rule_version);
        $this->assertSame(12_345, $quote->route_distance_m);
        $this->assertSame(hash('sha256', 'fake_polyline_abc'), $quote->polyline_hash);
        $this->assertTrue($quote->expires_at->between(now()->addSeconds(290), now()->addSeconds(301)));
        $this->assertTrue($this->app->make(QuoteSigner::class)->verify($quote->fresh()));
        $this->assertSame('quote', $option->toArray()['kind']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'pricing.quote_issued', 'subject_id' => $rules->id]);
    }

    public function test_an_estimate_is_never_stored_and_says_estimate(): void
    {
        $this->publishedRuleSet();
        $this->fakeRoute();

        $option = $this->quoter()->estimate($this->request('economy', User::factory()->create()));

        $this->assertInstanceOf(PricedOption::class, $option);
        $this->assertNull($option->quote);
        $this->assertSame('estimate', $option->toArray()['kind']);
        $this->assertStringContainsString('Estimate only', $option->toArray()['wording']);
        $this->assertSame(0, FareQuote::count());
    }

    public function test_no_published_tariff_is_reported(): void
    {
        $this->fakeRoute();
        $this->publishedRuleSet(['status' => 'draft']);

        $result = $this->quoter()->quote($this->request());

        $this->assertInstanceOf(QuoteUnavailable::class, $result);
        $this->assertSame('no_tariff', $result->reason);
    }

    public function test_inactive_and_unknown_categories_are_refused(): void
    {
        $this->fakeRoute();
        $this->publishedRuleSet();

        $this->assertSame('category_not_offered', $this->quoter()->quote($this->request('parcel'))->reason);
        $this->assertSame('category_not_offered', $this->quoter()->quote($this->request('business'))->reason);
        $this->assertSame('category_unknown', $this->quoter()->quote($this->request('helicopter'))->reason);
    }

    public function test_outside_every_service_area_cannot_be_quoted_once_geo_is_wired(): void
    {
        $this->fakeRoute();
        $this->publishedRuleSet();
        $this->useZones((new FakeZoneLookup)->addArea(new ServiceAreaRef(1, 'jhb', 'ZA', 'ZAR', 'Africa/Johannesburg'), new GeoPoint(-26.2041, 28.0473), 30_000));

        $result = $this->quoter()->quote($this->request());

        $this->assertSame('outside_service_area', $result->reason);
    }

    public function test_restricted_zone_refuses_and_airport_zone_adds_its_fee(): void
    {
        $this->fakeRoute();
        $this->publishedRuleSet(['zone_fees' => ['airport' => ['pickup_minor' => 2000, 'dropoff_minor' => 0, 'label' => 'Airport pickup fee']]]);
        $zones = (new FakeZoneLookup)->addArea(new ServiceAreaRef(1, 'cpt', 'ZA', 'ZAR', 'Africa/Johannesburg'), $this->capeTown(), 50_000);
        $zones->addZone(new ZoneRef(5, 'cpt-cbd', 'airport', 'airport'), $this->capeTown(), 200);
        $this->useZones($zones);

        $option = $this->quoter()->quote($this->request());
        $this->assertSame(2000, $option->breakdown->amountFor('zone_fee:airport:pickup'));

        $zones->addZone(new ZoneRef(6, 'sea-point-closed', 'restricted', null), $this->seaPoint(), 200);
        $this->assertSame('restricted_zone', $this->quoter()->quote($this->request())->reason);
    }

    public function test_area_specific_currency_and_timezone_come_from_the_service_area(): void
    {
        $this->fakeRoute();
        $this->publishedRuleSet(['country' => 'BW', 'currency' => 'BWP', 'timezone' => 'Africa/Gaborone', 'service_area_id' => 9]);
        $this->useZones((new FakeZoneLookup)->addArea(new ServiceAreaRef(9, 'gbe', 'BW', 'BWP', 'Africa/Gaborone'), $this->capeTown(), 50_000));

        $option = $this->quoter()->quote($this->request());

        $this->assertSame('BWP', $option->breakdown->currency);
    }

    public function test_demand_uplift_only_with_a_signal_and_an_enabled_rule_set(): void
    {
        $this->fakeRoute();
        $this->publishedRuleSet(['demand_enabled' => true, 'demand_cap_bp' => 15000]);

        $this->assertSame(0, $this->quoter()->quote($this->request())->breakdown->amountFor('demand'), 'no signal, no uplift');

        $this->app->instance(DemandSignal::class, new FakeDemandSignal(20_000));
        $option = $this->quoter()->quote($this->request());

        $this->assertSame(15_000, $option->breakdown->demandMultiplierBp);
        $this->assertGreaterThan(0, $option->breakdown->amountFor('demand'));
    }

    public function test_toll_estimate_is_included_for_pass_through_rule_sets(): void
    {
        $this->fakeRoute();
        $this->publishedRuleSet(['toll_mode' => 'pass_through']);
        $this->app->instance(TollEstimator::class, new FakeTollEstimator(4_300));

        $this->assertSame(4_300, $this->quoter()->quote($this->request())->breakdown->amountFor('tolls'));
    }

    public function test_compare_prices_every_offered_category_and_explains_the_rest(): void
    {
        $this->fakeRoute();
        $this->publishedRuleSet(['ride_category_id' => $this->category('economy')->id]);
        $this->publishedRuleSet(['ride_category_id' => $this->category('premium')->id, 'per_km_minor' => 1500]);
        $this->useSupply(new FakeSupplyCounter(['premium' => 0]));
        $passenger = User::factory()->create();

        $comparison = $this->quoter()->compare(new QuoteRequest($this->capeTown(), $this->seaPoint(), null, $passenger));

        $this->assertInstanceOf(QuoteComparison::class, $comparison);
        // D-1902: a category with nobody online is still priced; its supply says so and it cannot be requested now.
        $this->assertSame(['economy', 'premium'], array_map(fn ($o) => $o->category->key, $comparison->options));
        $this->assertSame('unknown', $comparison->options[0]->supply);
        $this->assertSame('none', $comparison->options[1]->supply);
        $this->assertFalse($comparison->options[1]->requestableNow());
        $this->assertSame(0, $comparison->options[1]->driversNearby);
        $this->assertStringContainsString('not guaranteed', strtolower($comparison->options[1]->toArray()['supply_note']));
        $reasons = collect($comparison->unavailable)->mapWithKeys(fn ($u) => [$u->categoryKey => $u->reason])->all();
        $this->assertArrayNotHasKey('premium', $reasons);
        $this->assertSame('no_tariff', $reasons['standard']);
        $this->assertArrayNotHasKey('parcel', $reasons, 'inactive categories are simply not offered');
        $this->assertSame(2, FareQuote::count());
    }

    public function test_compare_reports_routing_unavailable_once(): void
    {
        $this->publishedRuleSet();

        $this->assertInstanceOf(QuoteUnavailable::class, $this->quoter()->compare(new QuoteRequest($this->capeTown(), $this->seaPoint())));
    }

    public function test_quotes_are_immutable_after_issue(): void
    {
        $this->publishedRuleSet();
        $this->fakeRoute();
        $quote = $this->quoter()->quote($this->request('economy', User::factory()->create()))->quote;

        $this->expectException(ImmutablePricingRecord::class);
        $quote->update(['total_minor' => 1]);
    }
}
