<?php

namespace Tests\Feature\Dispatch;

use App\Domain\Dispatch\Contracts\DriverEligibility;
use App\Domain\Dispatch\Data\EligibilityResult;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Dispatch\Models\RideOffer;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use CreatesDispatchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQuotes();
    }

    private function driverWithVehicle(string $status = 'approved'): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $profile = DriverProfile::factory()->for($user)->create(['status' => $status, 'is_online' => false]);
        $vehicle = Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true, 'type' => 'standard']);
        // Documents verified (the safety gate); approval of the profile is what these tests vary.
        $this->approveAllDocuments($profile, $vehicle);

        return $user;
    }

    public function test_an_approved_driver_goes_online_through_the_api(): void
    {
        $driver = $this->driverWithVehicle();

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.online'))
            ->assertOk()
            ->assertJsonPath('availability.state', 'online')
            ->assertJsonPath('availability.heartbeat_stale', true);

        $this->assertTrue(DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->value('is_online'));
    }

    public function test_an_unapproved_driver_is_refused_with_reasons(): void
    {
        $driver = $this->driverWithVehicle('pending');

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.online'))
            ->assertForbidden()
            // The code comes from the safety verification gate (bound at integration, D-700).
            ->assertJsonPath('error', 'driver_not_approved');

        $this->assertNotSame('online', DriverAvailability::where('user_id', $driver->id)->value('state'));
    }

    public function test_the_safety_verification_seam_decides_who_may_go_online(): void
    {
        $this->app->instance(DriverEligibility::class, new class implements DriverEligibility
        {
            public function canGoOnline(User $driver, Vehicle $vehicle): EligibilityResult
            {
                return EligibilityResult::deny(['licence_expired' => 'Your driving licence has expired. Upload a current one.']);
            }
        });
        $driver = $this->driverWithVehicle();

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.online'))
            ->assertForbidden()
            ->assertJsonPath('error', 'licence_expired')
            ->assertJsonPath('message', 'Your driving licence has expired. Upload a current one.');
    }

    public function test_a_driver_without_a_vehicle_cannot_go_online(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        DriverProfile::factory()->for($user)->create(['status' => 'approved']);

        $this->actingAs($user)->postJson(route('ehail.dispatch.driver.online'))->assertStatus(422)->assertJsonPath('error', 'no_active_vehicle');
    }

    public function test_the_heartbeat_stores_the_position_only_while_online(): void
    {
        $driver = $this->driverWithVehicle();

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.heartbeat'), ['lat' => -33.92, 'lng' => 18.42])
            ->assertOk()->assertJsonPath('location_recorded', false);
        $this->assertNull(DriverAvailability::where('user_id', $driver->id)->value('last_lat'), 'an offline driver\'s position is not kept');

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.online'));
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.heartbeat'), ['lat' => -33.92, 'lng' => 18.42, 'heading' => 270])
            ->assertOk()->assertJsonPath('location_recorded', true);

        $row = DriverAvailability::where('user_id', $driver->id)->first();
        $this->assertEqualsWithDelta(-33.92, $row->last_lat, 0.000001);
        $this->assertSame(270, $row->heading);
        $this->assertFalse($row->isStale());
    }

    public function test_heartbeat_input_is_validated(): void
    {
        $driver = $this->driverWithVehicle();

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.heartbeat'), ['lat' => 120, 'lng' => 18])->assertStatus(422);
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.heartbeat'), ['lat' => -33.9])->assertStatus(422);
    }

    public function test_a_driver_goes_stale_without_heartbeats(): void
    {
        $driver = $this->onlineDriverAt(...self::PICKUP);
        $row = DriverAvailability::where('user_id', $driver->id)->first();
        $this->assertFalse($row->isStale());

        $this->travel(46)->seconds();
        $this->assertTrue($row->fresh()->isStale());
        $this->assertFalse($row->fresh()->hasFreshLocation());
        $this->assertSame('online', $row->fresh()->state, 'stale is not offline: the driver is just not offered rides');
    }

    public function test_going_offline_is_refused_during_a_trip(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(100));
        $ride = $this->requestRide();
        $this->actingAs($driver)->postJson(route('ehail.dispatch.offers.accept', RideOffer::where('pending_ride_id', $ride->id)->first()))->assertOk();

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.offline'))
            ->assertStatus(422)->assertJsonPath('error', 'on_trip');
        $this->assertSame('on_trip', DriverAvailability::where('user_id', $driver->id)->value('state'));
    }

    public function test_pausing_or_going_offline_withdraws_a_pending_offer_without_a_penalty(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(100));
        $ride = $this->requestRide();
        $offer = RideOffer::where('pending_ride_id', $ride->id)->first();

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.pause'))->assertOk()->assertJsonPath('availability.state', 'paused');

        $this->assertSame(RideOffer::WITHDRAWN, $offer->fresh()->status);
        $this->assertSame('driver_paused', $offer->fresh()->reason);

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.resume'))->assertOk()->assertJsonPath('availability.state', 'online');
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.offline'))->assertOk()->assertJsonPath('availability.state', 'offline');
        $this->assertFalse(DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->value('is_online'));
    }
}
