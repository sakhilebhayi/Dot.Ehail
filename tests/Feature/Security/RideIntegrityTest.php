<?php

namespace Tests\Feature\Security;

use App\Domain\Payments\Models\Payment;
use App\Domain\Pricing\Models\RideFare;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AcceptanceWorld;
use Tests\TestCase;

/**
 * R-46 security S-12 (legacy request path has a trip PIN) and S-17 (a no-show fee needs the driver at the pickup).
 */
class RideIntegrityTest extends TestCase
{
    use AcceptanceWorld, RefreshDatabase;

    public function test_a_ride_requested_through_the_legacy_form_carries_a_trip_pin_the_passenger_sees(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($passenger)->post(route('rides.store'), [
            'pickup_address' => 'Kloof Street', 'pickup_lat' => self::PICKUP[0], 'pickup_lng' => self::PICKUP[1],
            'dropoff_address' => 'Bree Street', 'dropoff_lat' => self::DESTINATION[0], 'dropoff_lng' => self::DESTINATION[1],
            'vehicle_type' => 'standard',
        ])->assertRedirect();

        $ride = Ride::sole();
        $this->assertNotNull($ride->pin_hash);

        $ride->forceFill(['status' => 'driver_assigned', 'driver_id' => $this->verifiedDriver()->id, 'accepted_at' => now()])->save();
        $this->actingAs($passenger)->get(route('rides.show', $ride))->assertOk()->assertSee($ride->pin_encrypted);
        $this->actingAs(User::find($ride->driver_id))->get(route('rides.show', $ride))->assertOk()->assertDontSee('Your trip PIN');
    }

    public function test_a_second_legacy_request_while_a_ride_is_active_is_refused_politely(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $form = ['pickup_address' => 'Kloof Street', 'dropoff_address' => 'Bree Street', 'vehicle_type' => 'standard'];

        $this->actingAs($passenger)->post(route('rides.store'), $form)->assertRedirect();
        $this->actingAs($passenger)->post(route('rides.store'), $form)->assertSessionHasErrors('pickup_address');
        $this->assertSame(1, Ride::count());
    }

    public function test_a_no_show_declared_away_from_the_pickup_costs_the_passenger_nothing(): void
    {
        $driver = $this->verifiedDriver('Dineo Mokoena');
        $this->goOnlineAt($driver, self::DRIVER_START);
        $passenger = User::factory()->withPersonalTeam()->create();
        $this->savedCard($passenger);

        $rideId = $this->requestRide($passenger, $this->quoteFor($passenger)['quote_id'])->assertCreated()->json('ride.id');
        $this->actingAs($driver)->postJson(route('ehail.dispatch.offers.accept', $this->driverFeed($driver)['offer']['id']))->assertOk();
        $this->stage($driver, $rideId, 'arriving')->assertOk();
        // "Arrived" tapped while the phone is still 1 km away.
        $this->stage($driver, $rideId, 'arrived')->assertOk();
        $this->travel(10)->minutes();
        $this->postPosition($driver, self::DRIVER_START[0], self::DRIVER_START[1]);

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.no-show', $rideId))->assertOk();

        $this->assertSame(0, Payment::where('ride_id', $rideId)->where('purpose', Payment::PURPOSE_CANCELLATION_FEE)->count());
        $this->assertSame('no_show_unverified', collect(RideFare::where('ride_id', $rideId)->value('adjustments'))->first()['code']);
    }

    public function test_a_no_show_at_the_pickup_still_charges_the_fee(): void
    {
        $driver = $this->verifiedDriver('Dineo Mokoena');
        $this->goOnlineAt($driver, self::DRIVER_START);
        $passenger = User::factory()->withPersonalTeam()->create();
        $this->savedCard($passenger);

        $rideId = $this->requestRide($passenger, $this->quoteFor($passenger)['quote_id'])->assertCreated()->json('ride.id');
        $this->acceptAndArrive($driver, $passenger, $rideId);
        $this->travel(10)->minutes();
        $this->postPosition($driver, self::PICKUP[0], self::PICKUP[1]);

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.no-show', $rideId))->assertOk();

        $this->assertSame('no_show_fee', collect(RideFare::where('ride_id', $rideId)->value('adjustments'))->first()['code']);
    }
}
