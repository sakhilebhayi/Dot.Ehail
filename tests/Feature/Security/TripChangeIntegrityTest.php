<?php

namespace Tests\Feature\Security;

use App\Domain\Business\Models\BusinessMember;
use App\Domain\Integration\Dispatch\PricingTripFareFinaliser;
use App\Domain\Rides\Models\RideEvent;
use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BusinessFixtures;
use Tests\TestCase;

/**
 * R-46 security S-18 (a passenger's change cannot be used to bill any distance) and S-19 (business rides keep
 * to the company's policy when changed mid-trip).
 */
class TripChangeIntegrityTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    private function underWay(int $rideId): Ride
    {
        $ride = Ride::findOrFail($rideId);
        $driver = $this->onlineDriverAt(...self::PICKUP);
        $ride->forceFill(['driver_id' => $driver->id, 'status' => 'in_progress', 'accepted_at' => now()->subMinutes(10), 'started_at' => now()->subMinutes(5)])->save();

        return $ride->fresh();
    }

    public function test_a_traveller_cannot_extend_a_business_ride_mid_trip(): void
    {
        [$account] = $this->businessAccount();
        $traveller = $this->member($account);
        $rideId = $this->requestBusinessRide($traveller)->assertCreated()->json('ride.id');
        $this->underWay($rideId);

        $this->actingAs($traveller->user)->postJson(route('ehail.dispatch.rides.trip-change', $rideId), ['kind' => 'destination', 'lat' => -33.80, 'lng' => 18.60])
            ->assertStatus(409)->assertJsonPath('error', 'business_change_not_allowed');
        $this->assertSame(0, RideEvent::where('ride_id', $rideId)->where('type', 'trip_changed')->count());
    }

    public function test_an_approver_may_change_a_business_ride(): void
    {
        [$account, $admin] = $this->businessAccount();
        $this->assertSame(BusinessMember::ADMIN, $admin->role);
        $rideId = $this->requestBusinessRide($admin)->assertCreated()->json('ride.id');
        $this->underWay($rideId);

        $this->actingAs($admin->user)->postJson(route('ehail.dispatch.rides.trip-change', $rideId), ['kind' => 'stop', 'lat' => -33.93, 'lng' => 18.42])->assertOk();
    }

    public function test_billable_distance_after_a_change_is_bounded_by_the_changed_trip(): void
    {
        $ride = new Ride(['pickup_lat' => -33.94, 'pickup_lng' => 18.41, 'dropoff_lat' => -33.913, 'dropoff_lng' => 18.4316]);
        $ride->id = 999_999;
        $events = collect([new RideEvent(['reason_code' => 'destination_changed', 'context' => ['lat' => -33.913, 'lng' => 18.4316]])]);
        $spoofed = (object) ['billableM' => 60_000, 'method' => 'gps', 'quality' => 'good', 'flags' => []];

        $bounded = app(PricingTripFareFinaliser::class)->boundedByChangedRoute($ride, $spoofed, $events);

        // About 3.5 km straight line: bounded near 7 km, far below the 60 km the trail claimed.
        $this->assertLessThan(8_000, $bounded->billableM);
        $this->assertContains('capped_to_changed_route', $bounded->flags);

        $honest = (object) ['billableM' => 5_000, 'method' => 'gps', 'quality' => 'good', 'flags' => []];
        $this->assertSame(5_000, app(PricingTripFareFinaliser::class)->boundedByChangedRoute($ride, $honest, $events)->billableM);
    }
}
