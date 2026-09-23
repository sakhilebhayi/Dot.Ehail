<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers RideLifecycleController: claiming an open request and moving it
 * forward through Ride.status. RideObserver (pre-existing, previously
 * dead beyond the "completed" transition) is exercised here too since it
 * fires automatically on any status change these actions make.
 */
class RideLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeAvailableDriver(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $profile = DriverProfile::factory()->for($user)->create(['status' => 'approved', 'is_online' => true]);
        Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true]);

        return $user;
    }

    private function makeRequestedRide(?User $passenger = null): Ride
    {
        $passenger ??= User::factory()->create();

        return Ride::create([
            'passenger_id' => $passenger->id,
            'pickup_address' => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'status' => 'requested',
            'vehicle_type' => 'standard',
            'estimated_fare' => 80,
        ]);
    }

    // ─── Available queue ─────────────────────────────────────────────────

    public function test_an_online_approved_driver_sees_open_requests(): void
    {
        $driver = $this->makeAvailableDriver();
        $ride = $this->makeRequestedRide();

        $this->actingAs($driver)
            ->get(route('rides.available'))
            ->assertOk()
            ->assertSee($ride->pickup_address);
    }

    public function test_a_non_driver_sees_a_prompt_to_apply_instead_of_the_queue(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->makeRequestedRide();

        $this->actingAs($user)
            ->get(route('rides.available'))
            ->assertOk()
            ->assertSee('not registered as a driver');
    }

    public function test_an_offline_driver_does_not_see_open_requests(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $profile = DriverProfile::factory()->for($user)->create(['status' => 'approved', 'is_online' => false]);
        Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true]);
        $ride = $this->makeRequestedRide();

        $this->actingAs($user)
            ->get(route('rides.available'))
            ->assertOk()
            ->assertDontSee($ride->pickup_address);
    }

    // ─── Accept ──────────────────────────────────────────────────────────

    public function test_an_available_driver_can_accept_an_open_ride(): void
    {
        $driver = $this->makeAvailableDriver();
        $ride = $this->makeRequestedRide();

        $this->actingAs($driver)
            ->post(route('rides.accept', $ride))
            ->assertRedirect(route('rides.show', $ride));

        $ride->refresh();
        $this->assertSame('accepted', $ride->status);
        $this->assertSame($driver->id, $ride->driver_id);
        $this->assertNotNull($ride->vehicle_id);
        $this->assertNotNull($ride->accepted_at);
    }

    public function test_a_driver_without_an_active_vehicle_cannot_accept(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        DriverProfile::factory()->for($user)->create(['status' => 'approved', 'is_online' => true]);
        $ride = $this->makeRequestedRide();

        $this->actingAs($user)
            ->post(route('rides.accept', $ride))
            ->assertForbidden();

        $this->assertSame('requested', $ride->fresh()->status);
    }

    public function test_an_offline_driver_cannot_accept(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $profile = DriverProfile::factory()->for($user)->create(['status' => 'approved', 'is_online' => false]);
        Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true]);
        $ride = $this->makeRequestedRide();

        $this->actingAs($user)
            ->post(route('rides.accept', $ride))
            ->assertForbidden();
    }

    public function test_a_second_driver_cannot_accept_an_already_claimed_ride(): void
    {
        $driverA = $this->makeAvailableDriver();
        $driverB = $this->makeAvailableDriver();
        $ride = $this->makeRequestedRide();

        $this->actingAs($driverA)->post(route('rides.accept', $ride));

        $this->actingAs($driverB)
            ->post(route('rides.accept', $ride))
            ->assertForbidden();

        $this->assertSame($driverA->id, $ride->fresh()->driver_id);
    }

    // ─── Advance ─────────────────────────────────────────────────────────

    public function test_the_assigned_driver_can_advance_the_ride_through_its_lifecycle(): void
    {
        $driver = $this->makeAvailableDriver();
        $ride = $this->makeRequestedRide();
        $this->actingAs($driver)->post(route('rides.accept', $ride));

        foreach (['en_route', 'arrived', 'in_progress', 'completed'] as $status) {
            $this->actingAs($driver)
                ->patch(route('rides.advance', $ride), ['status' => $status])
                ->assertRedirect();

            $this->assertSame($status, $ride->fresh()->status);
        }

        $this->assertNotNull($ride->fresh()->completed_at);
        $this->assertEquals(80, (float) $ride->fresh()->final_fare);
    }

    public function test_status_cannot_be_moved_backward(): void
    {
        $driver = $this->makeAvailableDriver();
        $ride = $this->makeRequestedRide();
        $this->actingAs($driver)->post(route('rides.accept', $ride));
        $this->actingAs($driver)->patch(route('rides.advance', $ride), ['status' => 'en_route']);

        $this->actingAs($driver)
            ->patch(route('rides.advance', $ride), ['status' => 'accepted'])
            ->assertSessionHasErrors('status');

        $this->assertSame('en_route', $ride->fresh()->status);
    }

    public function test_a_stage_cannot_be_skipped_past_completed_and_then_changed_again(): void
    {
        $driver = $this->makeAvailableDriver();
        $ride = $this->makeRequestedRide();
        $this->actingAs($driver)->post(route('rides.accept', $ride));
        foreach (['en_route', 'arrived', 'in_progress', 'completed'] as $status) {
            $this->actingAs($driver)->patch(route('rides.advance', $ride), ['status' => $status]);
        }

        $this->actingAs($driver)
            ->patch(route('rides.advance', $ride), ['status' => 'completed'])
            ->assertSessionHasErrors('status');
    }

    public function test_a_driver_not_assigned_to_the_ride_cannot_advance_it(): void
    {
        $driver = $this->makeAvailableDriver();
        $otherDriver = $this->makeAvailableDriver();
        $ride = $this->makeRequestedRide();
        $this->actingAs($driver)->post(route('rides.accept', $ride));

        $this->actingAs($otherDriver)
            ->patch(route('rides.advance', $ride), ['status' => 'en_route'])
            ->assertForbidden();
    }

    public function test_completing_a_ride_notifies_the_passenger_and_driver(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeAvailableDriver();
        $ride = $this->makeRequestedRide($passenger);
        $this->actingAs($driver)->post(route('rides.accept', $ride));

        foreach (['en_route', 'arrived', 'in_progress', 'completed'] as $status) {
            $this->actingAs($driver)->patch(route('rides.advance', $ride), ['status' => $status]);
        }

        $this->assertSame(1, $passenger->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $driver->fresh()->unreadNotifications()->count());
    }

    // ─── Cancel ──────────────────────────────────────────────────────────

    public function test_a_passenger_can_cancel_their_own_requested_ride(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRequestedRide($passenger);

        $this->actingAs($passenger)
            ->post(route('rides.cancel', $ride))
            ->assertRedirect(route('rides.show', $ride));

        $this->assertSame('cancelled', $ride->fresh()->status);
    }

    public function test_a_ride_in_progress_cannot_be_cancelled(): void
    {
        $driver = $this->makeAvailableDriver();
        $ride = $this->makeRequestedRide();
        $this->actingAs($driver)->post(route('rides.accept', $ride));
        $this->actingAs($driver)->patch(route('rides.advance', $ride), ['status' => 'en_route']);
        $this->actingAs($driver)->patch(route('rides.advance', $ride), ['status' => 'arrived']);
        $this->actingAs($driver)->patch(route('rides.advance', $ride), ['status' => 'in_progress']);

        $this->actingAs($driver)
            ->post(route('rides.cancel', $ride))
            ->assertForbidden();

        $this->assertSame('in_progress', $ride->fresh()->status);
    }

    public function test_an_unrelated_user_cannot_cancel_someone_elses_ride(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRequestedRide();

        $this->actingAs($stranger)
            ->post(route('rides.cancel', $ride))
            ->assertForbidden();
    }
}
