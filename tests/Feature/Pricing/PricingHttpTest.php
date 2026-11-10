<?php

namespace Tests\Feature\Pricing;

use App\Domain\Pricing\Finalisation\FareFinaliser;
use App\Domain\Pricing\Finalisation\TripActuals;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Domain\Pricing\Quotes\QuoteBinder;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\PricingFixtures;
use Tests\TestCase;

class PricingHttpTest extends TestCase
{
    use CreatesEhailFixtures, PricingFixtures, RefreshDatabase;

    private array $trip = ['pickup_lat' => -33.9249, 'pickup_lng' => 18.4241, 'dropoff_lat' => -33.9150, 'dropoff_lng' => 18.3870];

    protected function setUp(): void
    {
        parent::setUp();
        $this->publishedRuleSet();
    }

    public function test_public_estimate_is_anonymous_labelled_and_not_stored(): void
    {
        $this->fakeRoute();

        $this->postJson(route('pricing.estimate'), $this->trip + ['category' => 'economy', 'total' => 1, 'total_minor' => 1])
            ->assertOk()
            ->assertJsonPath('kind', 'estimate')
            ->assertJsonPath('fare.total.amount_minor', 10_500)
            ->assertJsonPath('quote_id', null)
            ->assertJsonPath('wording', 'Estimate only. The price you book is confirmed when you request the ride.');

        $this->assertSame(0, FareQuote::count());
    }

    public function test_public_estimate_compares_categories_and_reports_routing_honestly(): void
    {
        $this->postJson(route('pricing.estimate'), $this->trip)
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'routing_unavailable');

        $this->fakeRoute();
        $this->postJson(route('pricing.estimate'), $this->trip)->assertOk()->assertJsonPath('kind', 'estimate')->assertJsonCount(7, 'options');
    }

    public function test_public_estimate_is_rate_limited_and_validated(): void
    {
        config(['ehail_pricing.rate_limits.public_estimate_per_minute' => 2]);
        $this->fakeRoute();

        $this->postJson(route('pricing.estimate'), ['pickup_lat' => 200])->assertUnprocessable();
        $this->postJson(route('pricing.estimate'), $this->trip)->assertOk();
        $this->postJson(route('pricing.estimate'), $this->trip)->assertStatus(429)->assertJsonPath('message', 'Too many requests. Wait a minute and try again.');
    }

    public function test_public_catalogue_lists_active_ride_categories_only(): void
    {
        $keys = collect($this->getJson(route('pricing.estimate.categories'))->assertOk()->json('categories'))->pluck('key')->all();

        $this->assertNotContains('parcel', $keys);
        $this->assertNotContains('business', $keys);
        $this->assertContains('accessible', $keys);
    }

    public function test_quote_endpoint_requires_sign_in_and_issues_a_bookable_quote(): void
    {
        $this->fakeRoute();
        $this->postJson(route('pricing.quotes.store'), $this->trip + ['category' => 'economy'])->assertUnauthorized();

        $passenger = User::factory()->withPersonalTeam()->create();
        $response = $this->actingAs($passenger)->postJson(route('pricing.quotes.store'), $this->trip + ['category' => 'economy', 'total_minor' => 1])
            ->assertOk()
            ->assertJsonPath('kind', 'quote')
            ->assertJsonPath('fare.total.amount_minor', 10_500);

        $quoteId = $response->json('quote_id');
        $this->assertSame(10_500, FareQuote::findOrFail($quoteId)->total_minor, 'client-supplied totals are ignored');

        $this->actingAs($passenger)->getJson(route('pricing.quotes.show', $quoteId))->assertOk()->assertJsonPath('used', false);
        $this->actingAs(User::factory()->withPersonalTeam()->create())->getJson(route('pricing.quotes.show', $quoteId))->assertNotFound();
    }

    public function test_compare_endpoint_returns_quotes_for_each_category(): void
    {
        $this->fakeRoute();
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($passenger)->postJson(route('pricing.quotes.store'), $this->trip)->assertOk()->assertJsonCount(7, 'options');
        $this->assertSame(7, FareQuote::count());
    }

    public function test_available_categories_endpoint(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create())
            ->postJson(route('pricing.categories.available'), ['lat' => -33.9249, 'lng' => 18.4241])
            ->assertOk()
            ->assertJsonPath('serviced', true)
            ->assertJsonPath('categories.0.key', 'economy')
            ->assertJsonPath('categories.0.supply', 'unknown');
    }

    private function bookedRide(User $passenger, User $driver): Ride
    {
        $this->fakeRoute();
        $quoteId = $this->actingAs($passenger)->postJson(route('pricing.quotes.store'), $this->trip + ['category' => 'economy'])->json('quote_id');

        return DB::transaction(function () use ($passenger, $driver, $quoteId) {
            $ride = $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id], $passenger);
            $this->app->make(QuoteBinder::class)->bind($quoteId, $passenger, $this->capeTown(), $this->seaPoint(), $ride);

            return $ride;
        });
    }

    public function test_ride_fare_view_by_role(): void
    {
        $this->publishedCommissionPlan(['percent_bp' => 2000]);
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $ride = $this->bookedRide($passenger, $driver);

        $this->actingAs($passenger)->getJson(route('pricing.rides.fare', $ride))->assertOk()->assertJsonPath('final', null)->assertJsonMissingPath('earnings');
        $this->actingAs($driver)->getJson(route('pricing.rides.fare', $ride))->assertOk()->assertJsonPath('earnings.kind', 'estimate')->assertJsonPath('earnings.driver_net.amount_minor', 8_400);
        $this->actingAs(User::factory()->withPersonalTeam()->create())->getJson(route('pricing.rides.fare', $ride))->assertForbidden();

        $this->app->make(FareFinaliser::class)->finalise($ride, new TripActuals(10_000, 900, waitingSeconds: 420));

        $this->actingAs($passenger)->getJson(route('pricing.rides.fare', $ride))->assertOk()
            ->assertJsonPath('final.final.amount_minor', 10_900)
            ->assertJsonPath('final.changes.1.code', 'waiting_time');
        $this->actingAs($driver)->getJson(route('pricing.rides.fare', $ride))->assertJsonPath('earnings.kind', 'actual');
    }

    public function test_legacy_ride_fare_is_shown_unchanged(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide(['status' => 'completed', 'estimated_fare' => '80.00', 'final_fare' => '80.00'], $passenger);

        $this->actingAs($passenger)->getJson(route('pricing.rides.fare', $ride))->assertOk()
            ->assertJsonPath('legacy', true)
            ->assertJsonPath('final.amount_minor', 8000);
    }

    public function test_ops_endpoints_require_an_operator(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create())->getJson(route('pricing.ops.rule-sets.index'))->assertForbidden();
        $this->actingAs(User::factory()->withPersonalTeam()->create())->postJson(route('pricing.ops.rule-sets.store'), [])->assertForbidden();
    }

    public function test_rule_set_lifecycle_through_the_api(): void
    {
        $operator = $this->makeOperator();
        $this->actingAs($operator);

        $draft = $this->postJson(route('pricing.ops.rule-sets.store'), ['name' => 'Cape Town economy', 'base_minor' => 1200, 'per_km_minor' => 900, 'per_minute_minor' => 80, 'minimum_minor' => 3000, 'ride_category_id' => $this->category('economy')->id])
            ->assertCreated()->assertJsonPath('rule_set.status', 'draft')->json('rule_set');

        $this->patchJson(route('pricing.ops.rule-sets.update', $draft['id']), ['per_km_minor' => 950])->assertOk()->assertJsonPath('rule_set.per_km_minor', 950);
        $this->patchJson(route('pricing.ops.rule-sets.update', $draft['id']), ['per_km_minor' => -5])->assertUnprocessable();
        $this->patchJson(route('pricing.ops.rule-sets.update', $draft['id']), ['tax_mode' => 'exclusive'])->assertUnprocessable()->assertJsonValidationErrors('tax_rate_bp');
        $this->patchJson(route('pricing.ops.rule-sets.update', $draft['id']), ['time_windows' => [['start' => '25:00']]])->assertUnprocessable()->assertJsonValidationErrors('time_windows');

        $this->postJson(route('pricing.ops.rule-sets.publish', $draft['id']))->assertOk()->assertJsonPath('rule_set.status', 'published');
        $this->patchJson(route('pricing.ops.rule-sets.update', $draft['id']), ['per_km_minor' => 1])->assertStatus(409);
        $this->postJson(route('pricing.ops.rule-sets.publish', $draft['id']))->assertStatus(409);

        $v2 = $this->postJson(route('pricing.ops.rule-sets.version', $draft['id']), ['per_km_minor' => 1000])->assertCreated()->json('rule_set');
        $this->assertSame(2, $v2['version']);
        $this->postJson(route('pricing.ops.rule-sets.publish', $v2['id']))->assertOk();

        $this->assertSame('retired', PricingRuleSet::find($draft['id'])->status, 'publishing v2 supersedes v1');
        $this->assertSame(950, PricingRuleSet::find($draft['id'])->per_km_minor, 'v1 itself never changed');
        $this->assertDatabaseHas('audit_logs', ['action' => 'pricing.rule_set_published', 'subject_id' => $v2['id'], 'actor_user_id' => $operator->id]);

        $this->postJson(route('pricing.ops.rule-sets.retire', $v2['id']))->assertOk()->assertJsonPath('rule_set.status', 'retired');
        $this->getJson(route('pricing.ops.rule-sets.show', $v2['id']))->assertOk()->assertJsonCount(2, 'versions');
    }

    public function test_simulator_compares_a_draft_with_what_is_live(): void
    {
        $operator = $this->makeOperator();
        $draft = $this->publishedRuleSet(['status' => 'draft', 'per_km_minor' => 1600, 'effective_from' => null, 'published_at' => null]);

        $result = $this->actingAs($operator)->postJson(route('pricing.ops.rule-sets.simulate'), ['rule_set_ids' => [$draft->id], 'category' => 'economy', 'distance_m' => 10_000, 'duration_s' => 900])
            ->assertOk()->assertJsonPath('simulation', true)->assertJsonPath('trip.source', 'manual')->json('results');

        $totals = collect($result)->mapWithKeys(fn ($r) => [$r['status'] => $r['fare']['total']['amount_minor']])->all();
        $this->assertSame(['draft' => 18_500, 'published' => 10_500], $totals);
    }

    public function test_commission_promotion_category_and_fare_adjustment_admin(): void
    {
        $operator = $this->makeOperator();
        $this->actingAs($operator);

        $plan = $this->postJson(route('pricing.ops.commission-plans.store'), ['name' => 'Market 18%', 'percent_bp' => 1800])->assertCreated()->json('commission_plan');
        $this->postJson(route('pricing.ops.commission-plans.publish', $plan['id']))->assertOk()->assertJsonPath('commission_plan.status', 'published');
        $this->postJson(route('pricing.ops.commission-plans.store'), ['name' => 'Bad', 'percent_bp' => 9000])->assertUnprocessable();
        $v2 = $this->postJson(route('pricing.ops.commission-plans.version', $plan['id']), ['percent_bp' => 1700])->assertCreated()->json('commission_plan');
        $this->assertSame([2, 1700, 'Market 18%'], [$v2['version'], $v2['percent_bp'], $v2['name']]);
        $this->postJson(route('pricing.ops.commission-plans.publish', $v2['id']))->assertOk();
        $this->postJson(route('pricing.ops.commission-plans.retire', $plan['id']))->assertStatus(409);

        $this->postJson(route('pricing.ops.promotions.store'), ['code' => 'launch', 'name' => 'Launch', 'kind' => 'code', 'discount_type' => 'percent', 'percent_bp' => 2000, 'max_discount_minor' => 3000, 'currency' => 'ZAR', 'per_user_limit' => 1, 'eligibility' => ['first_ride_only' => true]])
            ->assertCreated()->assertJsonPath('promotion.code', 'LAUNCH');
        $this->postJson(route('pricing.ops.promotions.store'), ['code' => 'LAUNCH', 'name' => 'Dup', 'kind' => 'code', 'discount_type' => 'fixed', 'amount_minor' => 100, 'currency' => 'ZAR', 'per_user_limit' => 1])->assertUnprocessable();

        $this->patchJson(route('pricing.ops.categories.update', $this->category('xl')->id), ['display_order' => 5, 'availability' => ['windows' => [['start' => '06:00', 'end' => '22:00']]]])->assertOk()->assertJsonPath('category.display_order', 5);
        $this->patchJson(route('pricing.ops.categories.update', $this->category('parcel')->id), ['is_active' => true])->assertUnprocessable();

        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->bookedRide($passenger, $this->makeApprovedDriver());
        $this->actingAs($operator)->postJson(route('pricing.ops.rides.fare-adjustments.store', $ride), ['new_total_minor' => 5000, 'reason_code' => 'goodwill', 'text' => 'Long wait at pickup'])->assertUnprocessable()->assertJsonPath('reason', 'not_finalised');

        $this->app->make(FareFinaliser::class)->finalise($ride, new TripActuals(10_000, 900));
        $this->actingAs($operator)->postJson(route('pricing.ops.rides.fare-adjustments.store', $ride), ['new_total_minor' => 5000, 'reason_code' => 'goodwill', 'text' => 'Long wait at pickup'])
            ->assertOk()->assertJsonPath('fare.final.amount_minor', 5000)->assertJsonPath('fare.basis', 'operator_adjusted');
    }
}
