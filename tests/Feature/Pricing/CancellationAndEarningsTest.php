<?php

namespace Tests\Feature\Pricing;

use App\Domain\Pricing\Earnings\DriverArrangement;
use App\Domain\Pricing\Earnings\EarningsSplitter;
use App\Domain\Pricing\Engine\FareBreakdown;
use App\Domain\Pricing\Engine\FareComponent;
use App\Domain\Pricing\Finalisation\CancellationFeePolicy;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Pricing\Quotes\FareQuoter;
use App\Domain\Pricing\Quotes\QuoteBinder;
use App\Domain\Pricing\Quotes\QuoteRequest;
use App\Enums\RideActor;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\PricingFixtures;
use Tests\TestCase;

class CancellationAndEarningsTest extends TestCase
{
    use CreatesEhailFixtures, PricingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publishedRuleSet(['cancel_free_minutes' => 2, 'cancel_fee_minor' => 2500, 'no_show_fee_minor' => 3000, 'wait_free_minutes' => 5]);
        $this->fakeRoute(10_000, 900);
    }

    private function ride(array $attributes = [], ?string $promo = null): Ride
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $quote = $this->app->make(FareQuoter::class)->quote(new QuoteRequest($this->capeTown(), $this->seaPoint(), 'economy', $passenger, null, $promo))->quote;

        return DB::transaction(function () use ($passenger, $quote, $attributes) {
            $ride = $this->makeRide($attributes, $passenger);
            $this->app->make(QuoteBinder::class)->bind($quote->id, $passenger, $this->capeTown(), $this->seaPoint(), $ride);

            return $ride;
        });
    }

    private function policy(): CancellationFeePolicy
    {
        return $this->app->make(CancellationFeePolicy::class);
    }

    public function test_free_before_a_driver_is_assigned(): void
    {
        $ride = $this->ride(['status' => 'requested']);

        $this->assertNull($this->policy()->feeFor($ride, RideActor::Passenger));
        $this->assertSame('before_driver_assigned', $this->policy()->decide($ride, RideActor::Passenger)->code);
    }

    public function test_free_within_the_window_after_assignment_then_charged(): void
    {
        $this->freezeSecond();
        $driver = $this->makeApprovedDriver();
        $ride = $this->ride(['status' => 'accepted', 'driver_id' => $driver->id, 'accepted_at' => now()]);

        $this->assertNull($this->policy()->feeFor($ride, RideActor::Passenger, now()->addMinutes(2)));
        $this->assertSame(2500, $this->policy()->feeFor($ride, RideActor::Passenger, now()->addMinutes(2)->addSecond())->amountMinor);
    }

    public function test_no_show_fee_after_the_driver_waited_the_free_time(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->ride(['status' => 'arrived', 'driver_id' => $driver->id, 'accepted_at' => now()->subMinutes(10)]);

        $arrived = now()->subMinutes(6);
        $this->assertSame(3000, $this->policy()->feeFor($ride, 'passenger', now(), $arrived)->amountMinor);
        $this->assertSame('no_show_fee', $this->policy()->decide($ride, 'passenger', now(), $arrived)->code);

        // Arrived 3 minutes ago: the passenger still has free waiting time, the ordinary cancellation fee applies.
        $this->assertSame('cancellation_fee', $this->policy()->decide($ride, 'passenger', now(), now()->subMinutes(3))->code);
    }

    public function test_a_late_driver_waives_the_fee(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->ride(['status' => 'accepted', 'driver_id' => $driver->id, 'accepted_at' => now()->subMinutes(15)]);

        $this->assertSame(2500, $this->policy()->feeFor($ride, RideActor::Passenger)?->amountMinor);
        $this->assertSame('driver_late', $this->policy()->decide($ride, RideActor::Passenger, null, null, null, true)->code);
        $this->assertNull($this->policy()->feeFor($ride, RideActor::Passenger, null, null, null, true));
    }

    public function test_driver_and_operator_cancellations_never_charge_the_passenger(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->ride(['status' => 'accepted', 'driver_id' => $driver->id, 'accepted_at' => now()->subHour()]);

        $this->assertNull($this->policy()->feeFor($ride, RideActor::Driver));
        $this->assertNull($this->policy()->feeFor($ride, RideActor::Operator));
    }

    public function test_legacy_ride_has_no_fee(): void
    {
        $ride = $this->makeRide(['status' => 'accepted', 'driver_id' => $this->makeApprovedDriver()->id, 'accepted_at' => now()->subHour()]);

        $this->assertSame('no_snapshot', $this->policy()->decide($ride, RideActor::Passenger)->code);
    }

    public function test_settling_a_cancellation_records_the_fee_and_returns_the_promotion(): void
    {
        $promotion = Promotion::query()->create(['code' => 'BACK', 'name' => 'Back', 'kind' => 'code', 'discount_type' => 'fixed', 'amount_minor' => 1000, 'currency' => 'ZAR', 'per_user_limit' => 1, 'max_redemptions' => 1]);
        $driver = $this->makeApprovedDriver();
        $ride = $this->ride(['status' => 'accepted', 'driver_id' => $driver->id, 'accepted_at' => now()->subMinutes(10)], 'BACK');
        $this->assertSame(1, $promotion->fresh()->redemptions_count);

        $decision = $this->policy()->decide($ride, RideActor::Passenger);
        $this->policy()->settle($ride, $decision);

        $fare = RideFare::where('ride_id', $ride->id)->first();
        $this->assertSame('cancellation_fee', $fare->basis);
        $this->assertSame(2500, $fare->final_total_minor);
        $this->assertSame(0, $promotion->fresh()->redemptions_count);
        $this->assertSame(0, $promotion->fresh()->spent_minor);
    }

    private function breakdown(array $amounts): FareBreakdown
    {
        return new FareBreakdown(array_map(fn ($code, $amount) => new FareComponent($code, $code, $amount), array_keys($amounts), $amounts), 'ZAR');
    }

    private function arrangement(?int $fleetId = null, ?int $categoryId = null): DriverArrangement
    {
        return new DriverArrangement(1, $fleetId, 'ZA', 'ZAR', null, $categoryId);
    }

    public function test_commission_excludes_tolls_fees_tax_and_platform_funded_promotions(): void
    {
        $this->publishedCommissionPlan(['percent_bp' => 2000]);
        $fare = $this->breakdown(['base' => 1000, 'distance' => 8000, 'time' => 1500, 'booking_fee' => 300, 'zone_fee:airport:pickup' => 2000, 'tolls' => 4300, 'promotion' => -2000, 'tax' => 1000]);

        $split = $this->app->make(EarningsSplitter::class)->splitBreakdown($fare, $this->arrangement(), now(), false);

        $this->assertSame(10_500, $split->commissionableMinor);
        $this->assertSame(2_100, $split->commissionMinor);
        $this->assertSame(14_800, $split->driverGrossMinor);
        $this->assertSame(12_700, $split->driverNetMinor);
        $this->assertSame($fare->totalMinor() - 12_700, $split->platformMinor);
        $this->assertSame($split->driverGrossMinor - $split->commissionMinor, $split->driverNetMinor);
    }

    public function test_fixed_fee_min_and_max_caps(): void
    {
        $splitter = $this->app->make(EarningsSplitter::class);

        $this->publishedCommissionPlan(['percent_bp' => 1000, 'fixed_minor' => 200, 'min_commission_minor' => 1500, 'max_commission_minor' => 3000]);
        $this->assertSame(1500, $splitter->splitBreakdown($this->breakdown(['base' => 5000]), $this->arrangement(), now(), false)->commissionMinor, 'min');
        $this->assertSame(3000, $splitter->splitBreakdown($this->breakdown(['base' => 90_000]), $this->arrangement(), now(), false)->commissionMinor, 'max');
        $this->assertSame(2200, $splitter->splitBreakdown($this->breakdown(['base' => 20_000]), $this->arrangement(), now(), false)->commissionMinor, '10 % + R2');
        $this->assertSame(800, $splitter->splitBreakdown($this->breakdown(['base' => 800]), $this->arrangement(), now(), false)->commissionMinor, 'never more than the commissionable fare');
    }

    public function test_fleet_plan_overrides_the_market_plan_and_category_plans_apply(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $fleet = Fleet::factory()->create(['owner_user_id' => $owner->id]);
        $this->publishedCommissionPlan(['percent_bp' => 2500]);
        $this->publishedCommissionPlan(['percent_bp' => 1500, 'fleet_id' => $fleet->id]);
        $this->publishedCommissionPlan(['percent_bp' => 2000, 'ride_category_id' => $this->category('premium')->id]);
        $splitter = $this->app->make(EarningsSplitter::class);
        $fare = $this->breakdown(['base' => 10_000]);

        $this->assertSame(2500, $splitter->splitBreakdown($fare, $this->arrangement(), now(), false)->commissionMinor);
        $this->assertSame(1500, $splitter->splitBreakdown($fare, $this->arrangement($fleet->id), now(), false)->commissionMinor);
        $this->assertSame(2000, $splitter->splitBreakdown($fare, $this->arrangement(null, $this->category('premium')->id), now(), false)->commissionMinor);
        $this->assertSame(1500, $splitter->splitBreakdown($fare, $this->arrangement($fleet->id, $this->category('premium')->id), now(), false)->commissionMinor, 'fleet (8) beats category (2)');
    }

    public function test_without_a_plan_the_net_is_not_guessed(): void
    {
        $split = $this->app->make(EarningsSplitter::class)->splitBreakdown($this->breakdown(['base' => 10_000]), $this->arrangement(), now(), true);

        $this->assertNull($split->commissionMinor);
        $this->assertNull($split->driverNetMinor);
        $this->assertStringContainsString('No commission plan', $split->toDriverArray()['note']);
    }

    public function test_driver_sees_an_estimate_before_accepting_from_the_rides_quote(): void
    {
        $this->publishedCommissionPlan(['percent_bp' => 2000]);
        $driver = $this->makeApprovedDriver();
        $ride = $this->ride(['status' => 'requested']);

        $split = $this->app->make(EarningsSplitter::class)->estimateForRide($ride, $driver->id);

        $this->assertTrue($split->isEstimate);
        $this->assertSame(10_500, $split->fareMinor);
        $this->assertSame(8_400, $split->driverNetMinor);
        $this->assertSame('estimate', $split->toDriverArray()['kind']);
    }

    public function test_property_split_conserves_money(): void
    {
        $this->publishedCommissionPlan(['percent_bp' => 2250, 'fixed_minor' => 150, 'max_commission_minor' => 50_000]);
        $splitter = $this->app->make(EarningsSplitter::class);
        mt_srand(99);

        for ($i = 0; $i < 300; $i++) {
            $fare = $this->breakdown(['base' => mt_rand(0, 5000), 'distance' => mt_rand(0, 60_000), 'time' => mt_rand(0, 9000), 'booking_fee' => mt_rand(0, 500), 'tolls' => mt_rand(0, 5000), 'promotion' => -mt_rand(0, 3000)]);
            $split = $splitter->splitBreakdown($fare, $this->arrangement(), now(), false);

            $this->assertSame($fare->totalMinor(), $split->driverNetMinor + $split->platformMinor);
            $this->assertGreaterThanOrEqual(0, $split->commissionMinor);
            $this->assertLessThanOrEqual($split->commissionableMinor, $split->commissionMinor);
            $this->assertGreaterThanOrEqual($split->passThroughMinor, $split->driverNetMinor, 'tolls always reach the driver');
        }
    }
}
