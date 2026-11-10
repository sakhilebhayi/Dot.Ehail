<?php

namespace Tests\Feature\Pricing;

use App\Domain\Pricing\Exceptions\FareNotFinalisable;
use App\Domain\Pricing\Finalisation\FareAdjuster;
use App\Domain\Pricing\Finalisation\FareFinaliser;
use App\Domain\Pricing\Finalisation\FinalFare;
use App\Domain\Pricing\Finalisation\TripActuals;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Domain\Pricing\Models\Promotion;
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

/**
 * Quote: 10 km, 15 min under the test tariff (R10 base, R8/km, R1/min, R25 minimum, 5 free waiting minutes then
 * R2/min, tolerances 15 % distance / 25 % time, overrun cap 20 %) = R105.00.
 */
class FareFinalisationTest extends TestCase
{
    use CreatesEhailFixtures, PricingFixtures, RefreshDatabase;

    private PricingRuleSet $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = $this->publishedRuleSet(['toll_mode' => 'pass_through']);
        $this->fakeRoute(10_000, 900);
    }

    /**
     * @param  array<string, mixed>  $promotion
     */
    private function bookedRide(?string $promo = null): Ride
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $quote = $this->app->make(FareQuoter::class)->quote(new QuoteRequest($this->capeTown(), $this->seaPoint(), 'economy', $passenger, null, $promo))->quote;
        $driver = $this->makeApprovedDriver();

        return DB::transaction(function () use ($passenger, $quote, $driver) {
            $ride = $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id, 'estimated_fare' => '105.00'], $passenger);
            $this->app->make(QuoteBinder::class)->bind($quote->id, $passenger, $this->capeTown(), $this->seaPoint(), $ride);

            return $ride;
        });
    }

    private function finalise(Ride $ride, TripActuals $actuals): FinalFare
    {
        return $this->app->make(FareFinaliser::class)->finalise($ride, $actuals);
    }

    private function assertAdjustmentsExplainTheDifference(FinalFare $final): void
    {
        $sum = array_sum(array_map(fn ($a) => $a->amountMinor, $final->adjustments));
        $this->assertSame($final->total()->amountMinor - $final->quoted()->amountMinor, $sum, 'adjustments must explain every cent of difference');

        foreach ($final->adjustments as $adjustment) {
            $this->assertNotSame('', $adjustment->code);
            $this->assertNotSame('', $adjustment->text);
        }

        $this->assertSame(array_sum(array_column($final->breakdown->componentsArray(), 'amount_minor')), $final->total()->amountMinor);
    }

    public function test_traffic_delay_within_tolerance_keeps_the_quoted_fare(): void
    {
        $ride = $this->bookedRide();

        $final = $this->finalise($ride, new TripActuals(10_800, 1_100));

        $this->assertSame(10_500, $final->total()->amountMinor);
        $this->assertSame('quoted_fare_stands', $final->basis);
        $this->assertSame('quoted_fare_stands', $final->adjustments[0]->code);
        $this->assertSame('105.00', $ride->fresh()->final_fare);
        $this->assertAdjustmentsExplainTheDifference($final);
    }

    public function test_waiting_time_is_added_with_its_reason(): void
    {
        $ride = $this->bookedRide();

        $final = $this->finalise($ride, new TripActuals(10_000, 900, waitingSeconds: 11 * 60));

        // 6 minutes beyond the 5 free x R2 = R12
        $this->assertSame(11_700, $final->total()->amountMinor);
        $codes = array_map(fn ($a) => $a->code, $final->adjustments);
        $this->assertContains('waiting_time', $codes);
        $this->assertStringContainsString('6 min after the 5 free minutes', $final->adjustments[1]->text);
        $this->assertAdjustmentsExplainTheDifference($final);
    }

    public function test_tolls_paid_are_passed_through_with_a_reason(): void
    {
        $ride = $this->bookedRide();

        $final = $this->finalise($ride, new TripActuals(10_000, 900, tollsMinor: 4_300));

        $this->assertSame(14_800, $final->total()->amountMinor);
        $this->assertSame(4_300, $final->breakdown->amountFor('tolls'));
        $this->assertContains('tolls', array_map(fn ($a) => $a->code, $final->adjustments));
        $this->assertAdjustmentsExplainTheDifference($final);
    }

    public function test_destination_change_at_passenger_request_is_recalculated_without_a_cap(): void
    {
        $ride = $this->bookedRide();

        $final = $this->finalise($ride, new TripActuals(25_000, 2_100, destinationChangedByPassenger: true));

        // 1000 + 25 x 800 + 35 x 100 = 24 500, same rule version
        $this->assertSame(24_500, $final->total()->amountMinor);
        $this->assertSame('recalculated', $final->basis);
        $this->assertSame('destination_changed', $final->adjustments[0]->code);
        $this->assertSame(14_000, $final->adjustments[0]->amountMinor);
        $this->assertAdjustmentsExplainTheDifference($final);
    }

    public function test_a_much_longer_trip_nobody_asked_for_is_recalculated_but_capped(): void
    {
        $ride = $this->bookedRide();

        $final = $this->finalise($ride, new TripActuals(16_000, 2_400));

        // Recalculated 1000 + 12 800 + 4 000 = 17 800, capped at quote + 20 % = 12 600
        $this->assertSame(12_600, $final->total()->amountMinor);
        $this->assertSame(-5_200, $final->breakdown->amountFor('recalculation_cap'));
        $this->assertSame('route_longer', $final->adjustments[0]->code);
        $this->assertAdjustmentsExplainTheDifference($final);
    }

    public function test_short_trip_is_recalculated_down(): void
    {
        $ride = $this->bookedRide();

        $final = $this->finalise($ride, new TripActuals(2_000, 300, endedEarlyByPassenger: true));

        // 1000 + 1600 + 500 = 3 100 (above the R25 minimum)
        $this->assertSame(3_100, $final->total()->amountMinor);
        $this->assertSame('ended_early', $final->adjustments[0]->code);
        $this->assertAdjustmentsExplainTheDifference($final);
    }

    public function test_shorter_than_tolerance_without_a_flag_still_lowers_the_fare_and_the_minimum_applies(): void
    {
        $ride = $this->bookedRide();

        $final = $this->finalise($ride, new TripActuals(500, 120));

        $this->assertSame(2_500, $final->total()->amountMinor);
        $this->assertSame('route_shorter', $final->adjustments[0]->code);
        $this->assertSame(900, $final->breakdown->amountFor('minimum_adjustment'), '1000 + 400 + 200 = 1600, topped up to 2500');
    }

    public function test_untrustworthy_distance_never_moves_the_fare(): void
    {
        $ride = $this->bookedRide();

        $final = $this->finalise($ride, new TripActuals(40_000, 900, destinationChangedByPassenger: true, distanceMethod: 'gps_with_straight_gaps', distanceQuality: 'degraded', flags: ['unresolved_gap']));

        $this->assertSame(10_500, $final->total()->amountMinor);
        $this->assertStringContainsString('could not be measured reliably', $final->adjustments[0]->text);
    }

    public function test_promotion_amount_is_kept_on_recalculation(): void
    {
        Promotion::query()->create(['code' => 'R20', 'name' => 'R20 off', 'kind' => 'code', 'discount_type' => 'fixed', 'amount_minor' => 2000, 'currency' => 'ZAR', 'per_user_limit' => 1]);
        $ride = $this->bookedRide('R20');

        $final = $this->finalise($ride, new TripActuals(25_000, 2_100, destinationChangedByPassenger: true));

        $this->assertSame(-2000, $final->breakdown->amountFor('promotion'));
        $this->assertSame(22_500, $final->total()->amountMinor);
    }

    public function test_finalisation_is_idempotent_and_audited(): void
    {
        $ride = $this->bookedRide();
        $first = $this->finalise($ride, new TripActuals(10_000, 900, waitingSeconds: 600));
        $second = $this->finalise($ride, new TripActuals(99_000, 9_000));

        $this->assertSame($first->total()->amountMinor, $second->total()->amountMinor);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'pricing.fare_finalised')->count());
    }

    public function test_finalisation_uses_the_quoted_rule_version_even_after_a_new_version_is_published(): void
    {
        $ride = $this->bookedRide();
        $this->rules->update(['status' => 'retired', 'retired_at' => now()]);
        $this->publishedRuleSet(['per_km_minor' => 5000, 'lineage_key' => $this->rules->lineage_key, 'version' => 2]);

        $final = $this->finalise($ride, new TripActuals(25_000, 2_100, destinationChangedByPassenger: true));

        $this->assertSame(24_500, $final->total()->amountMinor);
    }

    public function test_legacy_ride_without_a_snapshot_keeps_its_recorded_fare(): void
    {
        $ride = $this->makeRide(['status' => 'in_progress', 'final_fare' => '64.00']);

        try {
            $this->finalise($ride, new TripActuals(10_000, 900));
            $this->fail('A legacy ride must not be re-priced.');
        } catch (FareNotFinalisable $e) {
            $this->assertSame('no_snapshot', $e->reason);
        }

        $this->assertSame('64.00', $ride->fresh()->final_fare);
    }

    public function test_earnings_are_snapshotted_on_finalisation(): void
    {
        $this->publishedCommissionPlan(['percent_bp' => 2000]);
        $ride = $this->bookedRide();

        $final = $this->finalise($ride, new TripActuals(10_000, 900, tollsMinor: 4_300));

        $earnings = $final->rideFare->earnings;
        // Commissionable 10 500 x 20 % = 2 100; gross 10 500 + 4 300 tolls; net 12 700
        $this->assertSame(2_100, $earnings['commission_minor']);
        $this->assertSame(14_800, $earnings['driver_gross_minor']);
        $this->assertSame(12_700, $earnings['driver_net_minor']);
        $this->assertSame(2_100, $earnings['platform_minor']);
    }

    public function test_operator_adjustment_is_audited_explained_and_mirrored(): void
    {
        $ride = $this->bookedRide();
        $this->finalise($ride, new TripActuals(10_000, 900));
        $operator = $this->makeOperator();

        $final = $this->app->make(FareAdjuster::class)->adjust($ride, $operator, 8_000, 'refund_route_issue', 'Driver took a longer route; partial refund.');

        $this->assertSame(8_000, $final->total()->amountMinor);
        $this->assertSame('operator_adjusted', $final->basis);
        $this->assertSame(-2_500, $final->breakdown->amountFor('operator_adjustment'));
        $this->assertSame('80.00', $ride->fresh()->final_fare);
        $this->assertDatabaseHas('audit_logs', ['action' => 'pricing.fare_adjusted_by_operator', 'actor_user_id' => $operator->id]);
        $this->assertAdjustmentsExplainTheDifference($final);
    }

    public function test_operator_adjustment_rules(): void
    {
        $ride = $this->bookedRide();
        $adjuster = $this->app->make(FareAdjuster::class);
        $operator = $this->makeOperator();

        foreach ([[100, 'goodwill', 'Explained enough'], [-1, 'goodwill', 'Negative fare'], [100, 'made_up', 'Unknown code'], [100, 'goodwill', 'no']] as [$total, $code, $text]) {
            try {
                $adjuster->adjust($ride, $operator, $total, $code, $text);
                $this->fail('Expected refusal');
            } catch (FareNotFinalisable $e) {
                $this->assertContains($e->reason, ['not_finalised', 'invalid_adjustment']);
            }
        }
    }

    public function test_property_final_fare_is_explained_and_never_negative_for_random_trips(): void
    {
        mt_srand(777);

        for ($i = 0; $i < 40; $i++) {
            $ride = $this->bookedRide();
            $final = $this->finalise($ride, new TripActuals(
                mt_rand(0, 40_000),
                mt_rand(0, 4_000),
                mt_rand(0, 1_800),
                mt_rand(0, 1) ? mt_rand(0, 9_000) : null,
                (bool) mt_rand(0, 1),
                false,
                (bool) mt_rand(0, 1),
                'gps',
                ['good', 'degraded', 'insufficient'][mt_rand(0, 2)],
            ));

            $this->assertGreaterThanOrEqual(0, $final->total()->amountMinor);
            $this->assertAdjustmentsExplainTheDifference($final);
        }
    }
}
