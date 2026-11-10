<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Payments\Models\Payment;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Rides\Models\RideEvent;
use App\Domain\Verification\Models\VerificationDocument;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AcceptanceWorld;
use Tests\TestCase;

/**
 * Failure scenarios through the real HTTP API (R46_INTEGRATION.md section 5): every one ends in an honest state
 * with a message for the person, nothing simulated.
 */
class FailureScenariosTest extends TestCase
{
    use AcceptanceWorld, RefreshDatabase;

    private function passenger(string $name = 'Zanele Mthembu'): User
    {
        $passenger = User::factory()->withPersonalTeam()->create(['name' => $name]);
        $this->savedCard($passenger);

        return $passenger;
    }

    /**
     * @param  array{0: float, 1: float}  $at
     */
    private function onlineDriver(string $name, array $at = self::DRIVER_START): User
    {
        $driver = $this->verifiedDriver($name);
        $this->goOnlineAt($driver, $at);

        return $driver;
    }

    public function test_without_a_routing_provider_nothing_is_priced_and_the_reason_is_honest(): void
    {
        $passenger = $this->passenger();
        $trip = ['pickup_lat' => self::PICKUP[0], 'pickup_lng' => self::PICKUP[1], 'dropoff_lat' => self::DESTINATION[0], 'dropoff_lng' => self::DESTINATION[1], 'category' => 'standard'];

        // Not configured at all.
        config(['ehail-geo.routing.driver' => 'none']);
        $this->actingAs($passenger)->postJson(route('pricing.quotes.store'), $trip)->assertOk()
            ->assertJsonPath('available', false)->assertJsonPath('reason', 'routing_unavailable')->assertJsonPath('detail', 'not_configured')
            ->assertJsonPath('message', 'Route pricing is unavailable: the operator has not configured a routing provider.');
        $this->actingAs($passenger)->getJson(route('geo.route', ['from_lat' => self::PICKUP[0], 'from_lng' => self::PICKUP[1], 'to_lat' => self::DESTINATION[0], 'to_lng' => self::DESTINATION[1]]))
            ->assertStatus(503)->assertJsonPath('reason', 'not_configured');

        // Configured but down: no price is invented from a straight line.
        config(['ehail-geo.routing.driver' => 'osrm']);
        $this->routingDown = true;
        $this->actingAs($passenger)->postJson(route('pricing.quotes.store'), $trip)->assertOk()
            ->assertJsonPath('available', false)->assertJsonPath('reason', 'routing_unavailable');
        $this->assertSame(0, Ride::count());
    }

    public function test_when_no_driver_takes_the_ride_it_expires_with_a_message(): void
    {
        $driver = $this->onlineDriver('Thabo Nkosi');
        $passenger = $this->passenger();
        $quote = $this->quoteFor($passenger);

        // The only driver goes offline after the quote. A request for now is refused while nobody is nearby (D-1902):
        // nothing is created and the price stays as quoted.
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.offline'))->assertOk();
        $this->requestRide($passenger, $quote['quote_id'])->assertStatus(409)->assertJsonPath('error', 'no_supply');
        $this->assertSame(0, Ride::count());

        // The counter saw a driver at the moment of the request, who was gone by the time dispatch looked (the race
        // the request gate cannot close): the ride searches and expires.
        $this->app->instance(\App\Domain\Pricing\Contracts\EligibleSupplyCounter::class, new \Tests\Doubles\Pricing\FakeSupplyCounter(['economy' => 1, 'standard' => 1]));
        $rideId = $this->requestRide($passenger, $quote['quote_id'])->assertCreated()->assertJsonPath('ride.status', 'searching')->json('ride.id');
        $this->assertSame(0, RideOffer::count());

        $this->travel(11)->minutes();
        $feed = $this->passengerFeed($passenger, $rideId);
        $this->assertSame('expired', $feed['ride']['status']);
        $this->assertNotNull($feed['ride']['timestamps']['expired_at']);
        $notice = $passenger->notifications()->latest()->first();
        $this->assertSame('No driver found', $notice->data['title']);
        $this->assertStringContainsString('expired: no driver accepted', $notice->data['message']);
        $this->assertSame('cancellation_fee', RideFare::where('ride_id', $rideId)->value('basis'), 'the fare record is closed');
        $this->assertSame(0, (int) RideFare::where('ride_id', $rideId)->value('final_total_minor'), 'and nothing is owed');
        $this->assertSame(0, Payment::count());
    }

    public function test_a_decline_and_a_timeout_each_move_the_offer_to_the_next_driver(): void
    {
        $near = $this->onlineDriver('Ayo Near', [self::PICKUP[0] - 0.0045, self::PICKUP[1]]);
        $middle = $this->onlineDriver('Ben Middle', [self::PICKUP[0] - 0.0090, self::PICKUP[1]]);
        $far = $this->onlineDriver('Cleo Far', [self::PICKUP[0] - 0.0135, self::PICKUP[1]]);
        $passenger = $this->passenger();
        $rideId = $this->requestRide($passenger, $this->quoteFor($passenger)['quote_id'])->assertCreated()->json('ride.id');

        $offer = $this->driverFeed($near)['offer'];
        $this->assertSame($rideId, $offer['ride_id'], 'the closest driver first');
        $this->actingAs($near)->postJson(route('ehail.dispatch.offers.decline', $offer['id']), ['reason' => 'destination'])->assertOk();
        $this->assertNull($this->driverFeed($near)['offer']);
        $this->assertSame($rideId, $this->driverFeed($middle)['offer']['ride_id'], 'a decline moves on at once');

        // The middle driver lets the offer run out; the passenger's next poll moves it on.
        $this->travel(16)->seconds();
        $this->passengerFeed($passenger, $rideId);
        $this->assertSame(RideOffer::EXPIRED, RideOffer::where('ride_id', $rideId)->where('driver_id', $middle->id)->value('status'));
        $offer = $this->driverFeed($far)['offer'];
        $this->assertSame($rideId, $offer['ride_id']);
        $this->actingAs($far)->postJson(route('ehail.dispatch.offers.accept', $offer['id']))->assertOk();
        $this->assertSame($far->id, Ride::find($rideId)->driver_id);
    }

    public function test_cancelling_after_assignment_costs_the_fee_the_policy_sets(): void
    {
        $driver = $this->onlineDriver('Dineo Mokoena');
        $passenger = $this->passenger();
        $cancel = fn (int $rideId) => $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.cancel', $rideId), ['reason' => 'changed_plans'])->assertOk();

        // Within the free window after assignment: free.
        $rideId = $this->requestRide($passenger, $this->quoteFor($passenger)['quote_id'])->assertCreated()->json('ride.id');
        $this->actingAs($driver)->postJson(route('ehail.dispatch.offers.accept', $this->driverFeed($driver)['offer']['id']))->assertOk();
        $this->travel(1)->minutes();
        $cancel($rideId)->assertJsonPath('ride.status', 'cancelled_by_passenger')->assertJsonPath('ride.fare.cancellation_fee_minor', null);
        $this->assertSame('within_free_window', collect(RideFare::where('ride_id', $rideId)->value('adjustments'))->first()['code']);

        // After the free window, with the driver on time: the cancellation fee, charged to the card.
        $this->postPosition($driver, self::DRIVER_START[0], self::DRIVER_START[1]);
        $rideId = $this->requestRide($passenger, $this->quoteFor($passenger)['quote_id'])->assertCreated()->json('ride.id');
        $this->actingAs($driver)->postJson(route('ehail.dispatch.offers.accept', $this->driverFeed($driver)['offer']['id']))->assertOk();
        $this->stage($driver, $rideId, 'arriving')->assertOk();
        $this->travel(150)->seconds();
        $this->postPosition($driver, self::PICKUP[0] - 0.002, self::PICKUP[1]);
        $cancel($rideId)->assertJsonPath('ride.fare.cancellation_fee_minor', 2500);
        $fee = Payment::where('ride_id', $rideId)->where('purpose', Payment::PURPOSE_CANCELLATION_FEE)->sole();
        $this->assertSame(Payment::PAID, $fee->status);
        $this->assertSame(2500, $fee->amountMinor());
        $this->assertSame(2000, DriverEarning::where('ride_id', $rideId)->where('kind', DriverEarning::KIND_CANCELLATION_FEE)->value('net_minor'));
        $this->assertTrue($this->ledgerBalances());

        // The driver running well past the promised pickup time: free again (pricing's late-driver waiver).
        $this->travel(5)->seconds();
        $this->postPosition($driver, self::DRIVER_START[0], self::DRIVER_START[1]);
        $rideId = $this->requestRide($passenger, $this->quoteFor($passenger)['quote_id'])->assertCreated()->json('ride.id');
        $this->actingAs($driver)->postJson(route('ehail.dispatch.offers.accept', $this->driverFeed($driver)['offer']['id']))->assertOk();
        $this->travel(9)->minutes();
        $cancel($rideId)->assertJsonPath('ride.fare.cancellation_fee_minor', null);
        $this->assertSame('driver_late', collect(RideFare::where('ride_id', $rideId)->value('adjustments'))->first()['code']);
        $this->assertSame(1, Payment::where('purpose', Payment::PURPOSE_CANCELLATION_FEE)->count());
    }

    public function test_a_declined_card_is_retried_and_then_blocks_the_next_request(): void
    {
        config(['money.charging.retry_minutes' => [5]]);
        $driver = $this->onlineDriver('Kabelo Sithole');
        $passenger = $this->passenger();
        $this->chargeOutcome = 'failed';

        $rideId = $this->tripUnderWay($passenger, $driver);
        $this->drive($driver, self::PICKUP, self::DESTINATION);
        $this->stage($driver, $rideId, 'complete')->assertOk();

        $first = Payment::where('ride_id', $rideId)->sole();
        $this->assertSame(Payment::FAILED, $first->status);
        $this->assertNotNull($first->next_retry_at, 'a retry is scheduled');
        $this->actingAs($passenger)->getJson(route('money.payment-due'))->assertOk()->assertJsonPath('blocked', false);

        $this->travel(6)->minutes();
        $this->artisan('money:sweep')->assertSuccessful();
        $this->assertSame(2, Payment::where('ride_id', $rideId)->count());
        $this->assertSame('failed', Ride::find($rideId)->payment_status);
        $this->actingAs($passenger)->getJson(route('money.payment-due'))->assertOk()->assertJsonPath('blocked', true);

        // The next request is refused until the passenger pays.
        $this->postPosition($driver, self::DRIVER_START[0], self::DRIVER_START[1]);
        $quote = $this->quoteFor($passenger);
        $this->requestRide($passenger, $quote['quote_id'])->assertStatus(402)->assertJsonPath('error', 'payment_due')
            ->assertJsonPath('payment_due.0.ride_id', $rideId);
        $this->assertSame(1, Ride::where('passenger_id', $passenger->id)->count());
    }

    public function test_an_unverified_driver_cannot_go_online_by_any_route(): void
    {
        $driver = $this->verifiedDriver('Unverified Udo');
        VerificationDocument::where('user_id', $driver->id)->where('type', 'prdp')->delete();

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.online'))->assertForbidden()->assertJsonPath('error', 'document_missing');
        $this->assertNotSame('online', DriverAvailability::where('user_id', $driver->id)->value('state'));

        // The pre-R-46 toggle on the driver page is refused by the same gate.
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->sole();
        $this->actingAs($driver)->patchJson(route('drivers.toggle-online', $profile))->assertStatus(422);
        $this->assertFalse((bool) $profile->fresh()->is_online);
    }

    public function test_an_expired_document_takes_an_online_driver_offline(): void
    {
        $driver = $this->onlineDriver('Expiring Eve');
        VerificationDocument::where('user_id', $driver->id)->where('type', 'drivers_licence')->update(['expires_on' => now()->addDay()->toDateString()]);

        $this->travel(2)->days();
        $this->artisan('ehail:verification-expiry')->assertSuccessful();

        $this->assertSame(DriverAvailability::OFFLINE, DriverAvailability::where('user_id', $driver->id)->value('state'));
        $this->assertFalse((bool) DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->value('is_online'));
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.online'))->assertForbidden()->assertJsonPath('error', 'document_expired');
    }

    public function test_the_same_idempotency_key_returns_the_same_ride(): void
    {
        $this->onlineDriver('Idem Ike');
        $passenger = $this->passenger();
        $quote = $this->quoteFor($passenger);

        $first = $this->requestRide($passenger, $quote['quote_id'], [], 'retry-on-flaky-network-01')->assertCreated()->json('ride.id');
        $this->requestRide($passenger, $quote['quote_id'], [], 'retry-on-flaky-network-01')->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('ride.id', $first);
        // A different key is a new request: refused while the first ride is active, never a second ride.
        $this->requestRide($passenger, $quote['quote_id'], [], 'retry-on-flaky-network-02')->assertStatus(409);
        $this->assertSame(1, Ride::count());
        $this->assertSame(1, RideFare::count());
    }

    public function test_two_drivers_taking_the_same_ride_at_once_only_one_wins(): void
    {
        config(['ehail_dispatch.open_board_after_seconds' => 0]);
        $offered = $this->onlineDriver('Offered Olu', [self::PICKUP[0] - 0.0045, self::PICKUP[1]]);
        $other = $this->onlineDriver('Board Bea', [self::PICKUP[0] - 0.0090, self::PICKUP[1]]);
        $passenger = $this->passenger();
        $rideId = $this->requestRide($passenger, $this->quoteFor($passenger)['quote_id'])->assertCreated()->json('ride.id');
        $offer = $this->driverFeed($offered)['offer'];

        // The other driver claims from the open board a moment before the offered driver taps accept.
        $this->actingAs($other)->post(route('rides.accept', $rideId))->assertRedirect(route('rides.show', $rideId));
        $this->actingAs($offered)->postJson(route('ehail.dispatch.offers.accept', $offer['id']))->assertStatus(409);

        $ride = Ride::find($rideId);
        $this->assertSame($other->id, $ride->driver_id);
        $this->assertSame(1, RideEvent::where('ride_id', $rideId)->where('to_status', 'driver_assigned')->count());
        $this->assertSame(DriverAvailability::ONLINE, DriverAvailability::where('user_id', $offered->id)->value('state'));
    }
}
