<?php

namespace Tests\Feature\Driver;

use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Pricing\Models\RideFare;
use App\Enums\RideStatus;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\Concerns\PricingFixtures;
use Tests\TestCase;

/**
 * The driver app's own JSON (offer earnings, arrival check, trip summary) and the ownership rules around the
 * dispatch actions it drives: a driver acts only on their own offer and ride.
 */
class DriverApiTest extends TestCase
{
    use CreatesDispatchFixtures, PricingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQuotes();
        // Document verification is the safety domain's (tested there and in DriverAppPagesTest); these drivers only
        // need to be online.
        config(['ehail_safety.verification.require_documents' => false]);
    }

    /**
     * @return array{0: User, 1: Ride, 2: RideOffer}
     */
    private function offered(): array
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(300));
        $ride = $this->requestRide();
        $offer = RideOffer::query()->where('pending_ride_id', $ride->id)->firstOrFail();
        $this->assertSame($driver->id, $offer->driver_id);

        return [$driver, $ride, $offer];
    }

    /**
     * @return array{0: User, 1: Ride}
     */
    private function assigned(): array
    {
        [$driver, $ride, $offer] = $this->offered();
        app(Dispatcher::class)->offerResponse($offer, $driver, 'accept');

        return [$driver, $ride->fresh()];
    }

    public function test_offer_earnings_breakdown_comes_from_the_pricing_split(): void
    {
        [$driver, $ride, $offer] = $this->offered();

        // No fare snapshot: honest "cannot be shown".
        $this->actingAs($driver)->getJson(route('drive.api.offer-earnings', $offer))
            ->assertOk()->assertJsonPath('available', false)->assertJsonPath('fare_minor', 8500);

        RideFare::query()->create([
            'ride_id' => $ride->id, 'currency' => 'ZAR', 'estimated_total_minor' => 10000,
            'estimated_components' => [['code' => 'base', 'label' => 'Base fare', 'amount_minor' => 4000], ['code' => 'distance', 'label' => 'Distance', 'amount_minor' => 6000]],
        ]);

        // A snapshot but no commission plan: net is null, never zero.
        $this->actingAs($driver)->getJson(route('drive.api.offer-earnings', $offer))
            ->assertOk()->assertJsonPath('available', true)->assertJsonPath('net_minor', null)->assertJsonPath('commission_minor', null);

        $this->publishedCommissionPlan(['percent_bp' => 2000]);

        $this->actingAs($driver)->getJson(route('drive.api.offer-earnings', $offer))
            ->assertOk()
            ->assertJsonPath('fare_minor', 10000)
            ->assertJsonPath('commission_minor', 2000)
            ->assertJsonPath('net_minor', 8000)
            ->assertJsonPath('deductions.0.code', 'commission');
    }

    public function test_a_driver_cannot_see_or_answer_another_drivers_offer(): void
    {
        [, , $offer] = $this->offered();
        $intruder = $this->onlineDriverAt(...$this->northOfPickup(5000));

        $this->actingAs($intruder)->getJson(route('drive.api.offer-earnings', $offer))->assertNotFound();
        $this->actingAs($intruder)->postJson(route('ehail.dispatch.offers.accept', $offer))->assertForbidden();
        $this->assertSame('pending', $offer->fresh()->status);
    }

    public function test_arrival_within_tolerance_goes_straight_through(): void
    {
        [$driver, $ride] = $this->assigned();
        $this->heartbeat($driver, $this->northOfPickup(60));

        $this->actingAs($driver)->postJson(route('drive.api.arrived', $ride))
            ->assertOk()
            ->assertJsonPath('ride.status', RideStatus::DriverArrived->value)
            ->assertJsonPath('arrival_check.within', true);
        $this->assertSame(0, AuditLog::query()->where('action', 'driver.arrived_outside_tolerance')->count());
    }

    public function test_arrival_far_away_needs_a_confirmation_that_is_audited(): void
    {
        [$driver, $ride] = $this->assigned();
        $this->heartbeat($driver, $this->northOfPickup(1500));

        $this->actingAs($driver)->postJson(route('drive.api.arrived', $ride))
            ->assertStatus(409)
            ->assertJsonPath('error', 'too_far')
            ->assertJsonPath('within', false);
        $this->assertSame(RideStatus::DriverAssigned->value, $ride->fresh()->status);

        $this->actingAs($driver)->postJson(route('drive.api.arrived', $ride), ['confirm_far' => true])
            ->assertOk()->assertJsonPath('ride.status', RideStatus::DriverArrived->value);

        $log = AuditLog::query()->where('action', 'driver.arrived_outside_tolerance')->firstOrFail();
        $this->assertGreaterThan(1400, $log->meta['distance_m']);
    }

    public function test_arrival_tolerance_includes_the_fix_accuracy_up_to_the_cap(): void
    {
        [$driver, $ride] = $this->assigned();
        $this->heartbeat($driver, $this->northOfPickup(320));

        $this->actingAs($driver)->postJson(route('drive.api.arrived', $ride), ['accuracy_m' => 30])->assertStatus(409);
        $this->actingAs($driver)->postJson(route('drive.api.arrived', $ride), ['accuracy_m' => 90])->assertOk();
    }

    public function test_another_driver_cannot_act_on_or_read_this_ride(): void
    {
        [, $ride] = $this->assigned();
        $intruder = $this->onlineDriverAt(...$this->northOfPickup(100));

        $this->actingAs($intruder)->postJson(route('drive.api.arrived', $ride), ['confirm_far' => true])->assertNotFound();
        $this->actingAs($intruder)->getJson(route('drive.api.summary', $ride))->assertNotFound();
        $this->actingAs($intruder)->postJson(route('ehail.dispatch.driver.rides.stage', ['ride' => $ride, 'stage' => 'arrived']))->assertForbidden();
        $this->actingAs($intruder)->get(route('drive.trips.show', $ride))->assertNotFound();
        $this->assertSame(RideStatus::DriverAssigned->value, $ride->fresh()->status);
    }

    public function test_trip_summary_shows_final_fare_against_the_estimate_and_the_split(): void
    {
        [$driver, $ride] = $this->assigned();
        RideFare::query()->create([
            'ride_id' => $ride->id, 'currency' => 'ZAR', 'estimated_total_minor' => 8500, 'final_total_minor' => 9100, 'finalised_at' => now(),
            'estimated_components' => [['code' => 'base', 'label' => 'Base fare', 'amount_minor' => 8500]],
            'final_components' => [['code' => 'base', 'label' => 'Base fare', 'amount_minor' => 8500], ['code' => 'waiting', 'label' => 'Waiting', 'amount_minor' => 600]],
            'adjustments' => [['code' => 'waiting', 'text' => 'Waiting time 6 min', 'amount_minor' => 600]],
            'earnings' => ['fare_minor' => 9100, 'commission_minor' => 1820, 'driver_net_minor' => 7280, 'deductions' => [['code' => 'commission', 'amount_minor' => 1820]]],
        ]);

        $this->actingAs($driver)->getJson(route('drive.api.summary', $ride))
            ->assertOk()
            ->assertJsonPath('fare.quoted_minor', 8500)
            ->assertJsonPath('fare.final_minor', 9100)
            ->assertJsonPath('fare.difference_minor', 600)
            ->assertJsonPath('fare.adjustments.0.text', 'Waiting time 6 min')
            ->assertJsonPath('earnings.source', 'pricing')
            ->assertJsonPath('earnings.commission_minor', 1820)
            ->assertJsonPath('earnings.net_minor', 7280)
            ->assertJsonPath('earnings.deductions_minor', 0);
    }

    public function test_the_driver_feed_carries_only_the_drivers_own_offer(): void
    {
        [$driver, , $offer] = $this->offered();
        $bystander = $this->onlineDriverAt(...$this->northOfPickup(6000));

        $this->actingAs($driver)->getJson(route('ehail.dispatch.driver.feed'))->assertOk()->assertJsonPath('offer.id', $offer->id);
        $this->actingAs($bystander)->getJson(route('ehail.dispatch.driver.feed'))->assertOk()->assertJsonPath('offer', null);
    }
}
