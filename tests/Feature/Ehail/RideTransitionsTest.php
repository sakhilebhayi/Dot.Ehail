<?php

namespace Tests\Feature\Ehail;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

/**
 * The transition table (App\Enums\RideStatus) is enforced by
 * RideLifecycle for every HTTP entry point. These cover the gaps the old
 * index-comparison left: a stage could previously be skipped.
 */
class RideTransitionsTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    private function acceptedRide(User $driver): Ride
    {
        $ride = $this->makeRide();
        $this->actingAs($driver)->post(route('rides.accept', $ride))->assertRedirect();

        return $ride->fresh();
    }

    public function test_a_stage_cannot_be_skipped(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->acceptedRide($driver);

        foreach (['arrived', 'in_progress', 'completed'] as $skipped) {
            $this->actingAs($driver)
                ->patch(route('rides.advance', $ride), ['status' => $skipped])
                ->assertSessionHasErrors('status');
        }

        $this->assertSame('accepted', $ride->fresh()->status);
        $this->assertNull($ride->fresh()->final_fare);
    }

    public function test_cancelled_is_not_a_stage_the_advance_endpoint_can_reach(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->acceptedRide($driver);

        $this->actingAs($driver)
            ->patch(route('rides.advance', $ride), ['status' => 'cancelled'])
            ->assertSessionHasErrors('status');

        $this->assertSame('accepted', $ride->fresh()->status);
    }

    public function test_a_terminal_ride_cannot_be_advanced_again(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'expired', 'driver_id' => $driver->id]);

        $this->actingAs($driver)
            ->patch(route('rides.advance', $ride), ['status' => 'en_route'])
            ->assertSessionHasErrors('status');

        $this->assertSame('expired', $ride->fresh()->status);
    }

    public function test_starting_the_trip_stamps_started_at(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->acceptedRide($driver);

        foreach (['en_route', 'arrived'] as $stage) {
            $this->actingAs($driver)->patch(route('rides.advance', $ride), ['status' => $stage]);
        }
        $this->assertNull($ride->fresh()->started_at);

        $this->actingAs($driver)->patch(route('rides.advance', $ride), ['status' => 'in_progress']);

        $this->assertNotNull($ride->fresh()->started_at);
    }

    public function test_a_flagged_ride_cannot_be_completed_by_its_driver(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'needs_review', 'driver_id' => $driver->id]);

        $this->actingAs($driver)
            ->patch(route('rides.advance', $ride), ['status' => 'completed'])
            ->assertSessionHasErrors('status');

        $this->assertSame('needs_review', $ride->fresh()->status);
    }

    public function test_a_driver_cannot_accept_their_own_ride_request(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide([], $driver);

        $this->actingAs($driver)->post(route('rides.accept', $ride))->assertForbidden();

        $this->assertSame('requested', $ride->fresh()->status);
    }
}
