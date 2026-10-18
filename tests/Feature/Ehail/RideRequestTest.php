<?php

namespace Tests\Feature\Ehail;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Before this pass, nothing anywhere in the codebase ever created a Ride --
 * RideController was read-only (index/show). These tests cover the
 * request half of the loop: RideRequestController.
 */
class RideRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_request_a_ride(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($passenger)->post(route('rides.store'), [
            'pickup_address' => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'vehicle_type' => 'standard',
        ]);

        $ride = Ride::where('passenger_id', $passenger->id)->firstOrFail();
        $response->assertRedirect(route('rides.show', $ride));
        $this->assertSame('requested', $ride->status);
        $this->assertNull($ride->driver_id);
    }

    public function test_requesting_with_coordinates_computes_distance_and_a_fare_estimate(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($passenger)->post(route('rides.store'), [
            'pickup_address' => 'Cape Town CBD',
            'pickup_lat' => -33.9249,
            'pickup_lng' => 18.4241,
            'dropoff_address' => 'Cape Town Airport',
            'dropoff_lat' => -33.9715,
            'dropoff_lng' => 18.6021,
            'vehicle_type' => 'standard',
        ]);

        $ride = Ride::where('passenger_id', $passenger->id)->firstOrFail();
        $this->assertNotNull($ride->distance_km);
        $this->assertNotNull($ride->estimated_fare);
        $this->assertGreaterThan(0, (float) $ride->estimated_fare);
    }

    public function test_requesting_without_coordinates_leaves_the_fare_estimate_null_instead_of_fabricating_one(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($passenger)->post(route('rides.store'), [
            'pickup_address' => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'vehicle_type' => 'standard',
        ]);

        $ride = Ride::where('passenger_id', $passenger->id)->firstOrFail();
        $this->assertNull($ride->distance_km);
        $this->assertNull($ride->estimated_fare);
    }

    public function test_a_guest_cannot_request_a_ride(): void
    {
        $this->post(route('rides.store'), [
            'pickup_address' => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'vehicle_type' => 'standard',
        ])->assertRedirect('/login');

        $this->assertDatabaseCount('rides', 0);
    }

    public function test_a_ride_request_requires_pickup_and_dropoff_addresses(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($passenger)
            ->post(route('rides.store'), ['vehicle_type' => 'standard'])
            ->assertSessionHasErrors(['pickup_address', 'dropoff_address']);

        $this->assertDatabaseCount('rides', 0);
    }
}
