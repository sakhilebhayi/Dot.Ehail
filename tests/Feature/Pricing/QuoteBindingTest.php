<?php

namespace Tests\Feature\Pricing;

use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Exceptions\QuoteRejected;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Models\PromotionRedemption;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Pricing\Quotes\FareQuoter;
use App\Domain\Pricing\Quotes\QuoteBinder;
use App\Domain\Pricing\Quotes\QuoteRequest;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\PricingFixtures;
use Tests\TestCase;

class QuoteBindingTest extends TestCase
{
    use CreatesEhailFixtures, PricingFixtures, RefreshDatabase;

    private User $passenger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publishedRuleSet();
        $this->fakeRoute();
        $this->passenger = User::factory()->withPersonalTeam()->create();
    }

    private function issue(?User $passenger = null, ?string $promo = null): FareQuote
    {
        $option = $this->app->make(FareQuoter::class)->quote(new QuoteRequest($this->capeTown(), $this->seaPoint(), 'economy', $passenger ?? $this->passenger, null, $promo));

        return $option->quote;
    }

    private function bind(FareQuote $quote, ?User $passenger = null, ?GeoPoint $pickup = null, ?GeoPoint $dropoff = null, ?string $category = null): RideFare
    {
        $passenger ??= $this->passenger;

        return DB::transaction(function () use ($quote, $passenger, $pickup, $dropoff, $category) {
            $ride = $this->makeRide(['estimated_fare' => null], $passenger);

            return $this->app->make(QuoteBinder::class)->bind($quote->id, $passenger, $pickup ?? $this->capeTown(), $dropoff ?? $this->seaPoint(), $ride, $category);
        });
    }

    private function assertRejected(string $reason, callable $attempt): void
    {
        $rides = Ride::count();

        try {
            $attempt();
            $this->fail("Expected the quote to be rejected ({$reason}).");
        } catch (QuoteRejected $e) {
            $this->assertSame($reason, $e->reason);
        }

        $this->assertSame($rides, Ride::count(), 'the ride creation must roll back with the refused quote');
    }

    public function test_binding_consumes_the_quote_and_snapshots_the_fare(): void
    {
        $quote = $this->issue();

        $fare = $this->bind($quote);

        $this->assertSame($quote->total_minor, $fare->estimated_total_minor);
        $this->assertSame($quote->components, $fare->estimated_components);
        $this->assertSame($quote->rule_set_id, $fare->rule_set_id);
        $this->assertNotNull($quote->fresh()->consumed_at);
        $this->assertSame($fare->ride_id, $quote->fresh()->ride_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'pricing.quote_bound', 'subject_id' => $fare->ride_id]);
    }

    public function test_a_quote_can_be_used_once(): void
    {
        $quote = $this->issue();
        $this->bind($quote);

        $this->assertRejected('already_used', fn () => $this->bind($quote));
    }

    public function test_expired_quote_is_refused(): void
    {
        $quote = $this->issue();
        $this->travel(301)->seconds();

        $this->assertRejected('expired', fn () => $this->bind($quote));
    }

    public function test_another_passengers_quote_looks_missing(): void
    {
        $quote = $this->issue();
        $other = User::factory()->withPersonalTeam()->create();

        $this->assertRejected('not_found', fn () => $this->bind($quote, $other));
    }

    public function test_tampered_quote_rows_are_refused(): void
    {
        $quote = $this->issue();
        // Change the stored price behind the model's back (as SQL or a bug would).
        DB::table('fare_quotes')->where('id', $quote->id)->update(['total_minor' => 1]);

        $this->assertRejected('tampered', fn () => $this->bind($quote));
    }

    public function test_tampered_route_or_tariff_version_is_refused(): void
    {
        $quote = $this->issue();
        DB::table('fare_quotes')->where('id', $quote->id)->update(['route_distance_m' => 1]);
        $this->assertRejected('tampered', fn () => $this->bind($quote));

        $second = $this->issue();
        DB::table('fare_quotes')->where('id', $second->id)->update(['rule_version' => 9]);
        $this->assertRejected('tampered', fn () => $this->bind($second));
    }

    public function test_route_mismatch_is_refused_but_small_gps_drift_is_accepted(): void
    {
        $quote = $this->issue();
        $this->assertRejected('route_mismatch', fn () => $this->bind($quote, null, $this->capeTown(), new GeoPoint(-33.95, 18.47)));

        // ~20 m away stays within the 75 m tolerance.
        $fare = $this->bind($quote, null, new GeoPoint(-33.92508, 18.4241));
        $this->assertNotNull($fare->id);
    }

    public function test_category_mismatch_is_refused(): void
    {
        $quote = $this->issue();

        $this->assertRejected('category_mismatch', fn () => $this->bind($quote, null, null, null, 'premium'));
    }

    public function test_promotion_is_redeemed_only_when_the_ride_is_created(): void
    {
        Promotion::query()->create(['code' => 'welcome', 'name' => 'Welcome R20', 'kind' => 'code', 'discount_type' => 'fixed', 'amount_minor' => 2000, 'currency' => 'ZAR', 'per_user_limit' => 1, 'max_redemptions' => 10]);

        $quote = $this->issue(null, 'WELCOME');
        $this->assertSame(2000, $quote->promotion_discount_minor);
        $this->assertSame(0, PromotionRedemption::count(), 'quoting does not redeem');

        $fare = $this->bind($quote);

        $this->assertSame(1, PromotionRedemption::count());
        $this->assertNotNull($fare->promotion_redemption_id);
        $this->assertSame(1, Promotion::first()->redemptions_count);
        $this->assertSame(2000, Promotion::first()->spent_minor);
    }

    public function test_redemption_limit_reached_between_quote_and_booking_refuses_the_booking(): void
    {
        $promotion = Promotion::query()->create(['code' => 'LAST', 'name' => 'Last slot', 'kind' => 'code', 'discount_type' => 'fixed', 'amount_minor' => 1000, 'currency' => 'ZAR', 'per_user_limit' => 1, 'max_redemptions' => 1]);
        $other = User::factory()->withPersonalTeam()->create();

        // Both passengers are quoted while one slot is left (the race), then one books first.
        $mine = $this->issue(null, 'LAST');
        $theirs = $this->issue($other, 'LAST');
        $this->assertSame(1000, $mine->promotion_discount_minor);
        $this->assertSame(1000, $theirs->promotion_discount_minor);

        $this->bind($theirs, $other);

        $this->assertRejected('promotion_unavailable', fn () => $this->bind($mine));
        $this->assertSame(1, $promotion->fresh()->redemptions_count);
        $this->assertNull($mine->fresh()->consumed_at, 'the refused quote stays unconsumed');
    }

    public function test_budget_cannot_be_overspent_by_racing_bookings(): void
    {
        $promotion = Promotion::query()->create(['code' => 'BUDGET', 'name' => 'Budget', 'kind' => 'code', 'discount_type' => 'fixed', 'amount_minor' => 1500, 'currency' => 'ZAR', 'per_user_limit' => 5, 'budget_minor' => 2000]);
        $other = User::factory()->withPersonalTeam()->create();

        $a = $this->issue(null, 'BUDGET');
        $b = $this->issue($other, 'BUDGET');
        $this->bind($a);

        $this->assertRejected('promotion_unavailable', fn () => $this->bind($b, $other));
        $this->assertSame(1500, $promotion->fresh()->spent_minor);
    }

    public function test_per_user_limit_is_rechecked_at_booking(): void
    {
        Promotion::query()->create(['code' => 'ONCE', 'name' => 'Once', 'kind' => 'code', 'discount_type' => 'percent', 'percent_bp' => 1000, 'currency' => 'ZAR', 'per_user_limit' => 1]);

        $first = $this->issue(null, 'ONCE');
        $second = $this->issue(null, 'ONCE');
        $this->bind($first);

        $this->assertRejected('promotion_unavailable', fn () => $this->bind($second));
    }

    public function test_a_conditional_update_alone_prevents_taking_a_full_promotion(): void
    {
        $promotion = Promotion::query()->create(['code' => 'FULL', 'name' => 'Full', 'kind' => 'code', 'discount_type' => 'fixed', 'amount_minor' => 500, 'currency' => 'ZAR', 'per_user_limit' => 9, 'max_redemptions' => 1]);
        $quote = $this->issue(null, 'FULL');
        // Simulate a concurrent transaction that took the last slot after our lock check would have passed.
        DB::table('promotions')->where('id', $promotion->id)->update(['redemptions_count' => 1]);

        $this->assertRejected('promotion_unavailable', fn () => $this->bind($quote));
    }
}
