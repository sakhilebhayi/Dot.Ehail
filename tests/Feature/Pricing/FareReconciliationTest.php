<?php

namespace Tests\Feature\Pricing;

use App\Domain\Pricing\Earnings\DriverArrangement;
use App\Domain\Pricing\Earnings\EarningsSplitter;
use App\Domain\Pricing\Engine\FareBreakdown;
use App\Domain\Pricing\Engine\FareComponent;
use App\Domain\Pricing\Engine\FareEngine;
use App\Domain\Pricing\Engine\FareInputs;
use App\Domain\Pricing\Finalisation\FareFinaliser;
use App\Domain\Pricing\Finalisation\TripActuals;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Domain\Pricing\Money\RoundingMode;
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
 * The jury's reconciliation findings as invariants (D-1900, D-1901): every money surface adds up, and every
 * multiplication a person is shown ("5.7 km x R7.50/km") gives the amount on its line.
 */
class FareReconciliationTest extends TestCase
{
    use CreatesEhailFixtures, PricingFixtures, RefreshDatabase;

    private array $trip = ['pickup_lat' => -33.9249, 'pickup_lng' => 18.4241, 'dropoff_lat' => -33.9150, 'dropoff_lng' => 18.3870];

    /** The example economy tariff the jury saw: R10 base, R7.50/km, R0.70/min, R3 booking fee, whole-rand rounding. */
    private function juryTariff(array $overrides = []): array
    {
        return array_merge(['base_minor' => 1000, 'per_km_minor' => 750, 'per_minute_minor' => 70, 'booking_fee_minor' => 300, 'minimum_minor' => 2500, 'rounding_unit_minor' => 100], $overrides);
    }

    /** Sum of components = total, and each metered line is its shown rate x its shown quantity. */
    private function assertReconciles(FareBreakdown $fare, RoundingMode $mode = RoundingMode::HalfUp, string $where = ''): void
    {
        $this->assertSame(array_sum(array_map(fn (FareComponent $c) => $c->amountMinor, $fare->components)), $fare->totalMinor(), $where.': components sum to the total');

        foreach ($fare->components as $c) {
            if (! $c->isMetered()) {
                continue;
            }

            $this->assertSame(FareComponent::meteredAmount($c->rateMinor, $c->quantityTenths, $mode), $c->amountMinor, $where.': '.$c->code.' is rate x quantity');
            // The detail a person reads carries the same quantity the amount was computed from.
            $this->assertStringStartsWith(FareComponent::quantityText($c->quantityTenths).' '.$c->unit.' ', (string) $c->detail, $where.': '.$c->code.' shows the billed quantity');
            $this->assertStringContainsString(' x ', (string) $c->detail, $where.': '.$c->code.' shows the multiplication');
        }
    }

    public function test_the_jury_example_reconciles_line_by_line(): void
    {
        // Screenshot 11: 5.714 km and 686 s. 5.7 km x R7.50 = R42.75 (was shown as R42.86); 11.4 min x R0.70 = R7.98.
        $fare = (new FareEngine)->compute(new PricingRuleSet($this->engineRules($this->juryTariff())), new FareInputs(5714, 686));

        $amounts = collect($fare->components)->mapWithKeys(fn (FareComponent $c) => [$c->code => $c->amountMinor])->all();
        $this->assertSame(['base' => 1000, 'distance' => 4275, 'time' => 798, 'booking_fee' => 300, 'rounding' => 27], $amounts);
        $this->assertSame(6400, $fare->totalMinor());
        $this->assertSame('5.7 km x R7.50/km', $fare->components[1]->detail);
        $this->assertSame('11.4 min x R0.70/min', $fare->components[2]->detail);
        $this->assertReconciles($fare);

        // Screenshot 05 (the public calculator) priced 5.707 km and 685 s: the same billed quantities, the same fare.
        $calculator = (new FareEngine)->compute(new PricingRuleSet($this->engineRules($this->juryTariff())), new FareInputs(5707, 685));
        $this->assertSame($fare->componentsArray(), $calculator->componentsArray());
    }

    public function test_property_every_fare_reconciles_whatever_the_tariff(): void
    {
        mt_srand(4646);

        for ($i = 0; $i < 400; $i++) {
            $mode = [RoundingMode::HalfUp, RoundingMode::Up, RoundingMode::Down][mt_rand(0, 2)];
            $rules = $this->engineRules([
                'base_minor' => mt_rand(0, 3000), 'per_km_minor' => mt_rand(0, 2500), 'per_minute_minor' => mt_rand(0, 400), 'minimum_minor' => mt_rand(0, 6000),
                'booking_fee_minor' => mt_rand(0, 600), 'wait_free_minutes' => mt_rand(0, 6), 'wait_per_minute_minor' => mt_rand(0, 400),
                'rounding_unit_minor' => [1, 10, 50, 100][mt_rand(0, 3)], 'rounding_mode' => $mode->value,
                'tax_mode' => ['none', 'inclusive', 'exclusive'][mt_rand(0, 2)], 'tax_rate_bp' => 1500,
            ]);

            $fare = (new FareEngine)->compute(new PricingRuleSet($rules), new FareInputs(mt_rand(0, 250_000), mt_rand(0, 12_000), waitingSeconds: mt_rand(0, 1500)));

            $this->assertReconciles($fare, $mode, 'case '.$i);
        }
    }

    public function test_the_public_calculator_and_the_booking_quote_compute_the_same_fare_for_the_same_route(): void
    {
        $this->publishedRuleSet($this->juryTariff());
        $this->fakeRoute(5714, 686);

        $estimate = $this->postJson(route('pricing.estimate'), $this->trip + ['category' => 'economy'])->assertOk()->assertJsonPath('kind', 'estimate');
        $quote = $this->actingAs(User::factory()->withPersonalTeam()->create())
            ->postJson(route('pricing.quotes.store'), $this->trip + ['category' => 'economy'])->assertOk()->assertJsonPath('kind', 'quote');

        $this->assertSame(6400, $estimate->json('fare.total.amount_minor'));
        $this->assertSame($estimate->json('fare.total.amount_minor'), $quote->json('fare.total.amount_minor'));
        $this->assertSame($this->lines($estimate->json('fare.components')), $this->lines($quote->json('fare.components')));
        $this->assertSame(
            [['base', 1000, null], ['distance', 4275, '5.7 km x R7.50/km'], ['time', 798, '11.4 min x R0.70/min'], ['booking_fee', 300, null], ['rounding', 27, null]],
            array_map(fn (array $l) => [$l[0], $l[1], in_array($l[0], ['distance', 'time'], true) ? $l[2] : null], $this->lines($quote->json('fare.components'))),
        );
        $this->assertSame(array_sum(array_column($quote->json('fare.components'), 'amount_minor')), $quote->json('fare.total.amount_minor'));
        // The metered lines carry what they were billed on, for any screen that wants to show the sum.
        $this->assertSame(['quantity' => '5.7', 'quantity_tenths' => 57, 'unit' => 'km', 'rate_minor' => 750], array_intersect_key($quote->json('fare.components.1'), array_flip(['quantity', 'quantity_tenths', 'unit', 'rate_minor'])));
    }

    public function test_the_final_fare_reconciles_on_the_billed_quantities(): void
    {
        $this->publishedRuleSet($this->juryTariff(['distance_tolerance_bp' => 0, 'duration_tolerance_bp' => 0, 'overrun_cap_bp' => 10_000]));
        $ride = $this->bookedRide(5714, 686);

        // The trip ran longer than quoted: 6 449 m and 761 s bill as 6.4 km and 12.7 min.
        $final = $this->app->make(FareFinaliser::class)->finalise($ride, new TripActuals(6449, 761));

        $this->assertReconciles($final->breakdown, RoundingMode::HalfUp, 'final');
        $this->assertSame($final->breakdown->totalMinor(), $final->total()->amountMinor);

        $this->assertSame('recalculated', $final->basis);
        $this->assertSame(4800, $final->breakdown->amountFor('distance'), '6.4 km x R7.50');
        $this->assertSame(889, $final->breakdown->amountFor('time'), '12.7 min x R0.70');
    }

    public function test_the_driver_split_lists_every_fee_and_names_the_commission_base(): void
    {
        // Screenshot 34: fare R64.00, "20% commission -R12.20", "You earn R48.80". The R3 booking fee was missing.
        $this->publishedRuleSet($this->juryTariff());
        $this->publishedCommissionPlan(['percent_bp' => 2000]);
        $driver = $this->makeApprovedDriver();
        $ride = $this->bookedRide(5714, 686);

        $split = $this->app->make(EarningsSplitter::class)->estimateForRide($ride, $driver->id);

        $this->assertSame(6400, $split->fareMinor);
        $this->assertSame(4880, $split->driverNetMinor);
        $lines = collect($split->walk())->keyBy('code');
        $this->assertSame(-300, $lines['not_earned:booking_fee']['amount_minor']);
        $this->assertStringContainsString('kept by the platform', $lines['not_earned:booking_fee']['label']);
        $this->assertSame(-1220, $lines['commission']['amount_minor']);
        $this->assertSame('20% of R61.00 (fare before fees)', $lines['commission']['detail']);
        $this->assertSame('R61.00 (fare before fees)', $split->commissionBase);
        $this->assertSame($split->driverNetMinor, $split->fareMinor + array_sum(array_column($split->walk(), 'amount_minor')), 'fare + lines = net');
        // The displayed percentage holds on the displayed base: 20% of R61.00 is R12.20.
        $this->assertSame(1220, intdiv(6100 * 2000, 10_000));
    }

    public function test_property_the_split_walk_always_lands_on_the_net(): void
    {
        $this->publishedCommissionPlan(['percent_bp' => 2250, 'fixed_minor' => 150, 'min_commission_minor' => 300, 'max_commission_minor' => 50_000]);
        $splitter = $this->app->make(EarningsSplitter::class);
        mt_srand(77);

        for ($i = 0; $i < 300; $i++) {
            $amounts = ['base' => mt_rand(0, 5000), 'distance' => mt_rand(0, 60_000), 'time' => mt_rand(0, 9000), 'booking_fee' => mt_rand(0, 500), 'zone_fee:airport:pickup' => mt_rand(0, 1) * 2000,
                'tolls' => mt_rand(0, 5000), 'promotion' => -mt_rand(0, 3000), 'tax' => mt_rand(0, 2000), 'rounding' => mt_rand(-50, 50)];
            $fare = new FareBreakdown(array_map(fn ($code, $amount) => new FareComponent($code, $code, $amount), array_keys($amounts), $amounts), 'ZAR');

            $split = $splitter->splitBreakdown($fare, new DriverArrangement(1, null, 'ZA', 'ZAR', null, null), now(), false);

            $this->assertSame($split->driverNetMinor, $split->fareMinor + array_sum(array_column($split->walk(), 'amount_minor')), 'case '.$i.': fare + lines = net');
            $this->assertSame($fare->totalMinor(), $split->driverNetMinor + $split->platformMinor, 'case '.$i.': nothing lost');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function engineRules(array $overrides = []): array
    {
        return array_merge([
            'name' => 't', 'status' => 'published', 'version' => 1, 'country' => 'ZA', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
            'base_minor' => 1000, 'per_km_minor' => 800, 'per_minute_minor' => 100, 'minimum_minor' => 2500, 'booking_fee_minor' => 0,
            'wait_free_minutes' => 5, 'wait_per_minute_minor' => 200, 'cancel_free_minutes' => 2, 'cancel_fee_minor' => 0, 'no_show_fee_minor' => 0,
            'zone_fees' => null, 'toll_mode' => 'none', 'demand_enabled' => false, 'demand_cap_bp' => 10000, 'tax_mode' => 'none', 'tax_rate_bp' => 0,
            'rounding_unit_minor' => 1, 'rounding_mode' => 'half_up', 'distance_tolerance_bp' => 1500, 'duration_tolerance_bp' => 2500, 'overrun_cap_bp' => 2000,
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return list<array{0: string, 1: int, 2: ?string}>
     */
    private function lines(array $components): array
    {
        return array_map(fn (array $c) => [$c['code'], $c['amount_minor'], $c['detail'] ?? null], $components);
    }

    private function bookedRide(int $distanceM, int $durationS): Ride
    {
        $this->fakeRoute($distanceM, $durationS);
        $passenger = User::factory()->withPersonalTeam()->create();
        $quote = $this->app->make(FareQuoter::class)->quote(new QuoteRequest($this->capeTown(), $this->seaPoint(), 'economy', $passenger))->quote;

        return DB::transaction(function () use ($passenger, $quote) {
            $ride = $this->makeRide(['status' => 'in_progress'], $passenger);
            $this->app->make(QuoteBinder::class)->bind($quote->id, $passenger, $this->capeTown(), $this->seaPoint(), $ride);

            return $ride;
        });
    }
}
