<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * is_online has existed on driver_profiles since the original schema, but
 * nothing ever set it after profile creation (it defaults to false) --
 * an approved driver had no way to ever become eligible to accept a ride.
 */
class DriverOnlineToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_driver_can_go_online_and_offline(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $profile = DriverProfile::factory()->for($user)->create(['status' => 'approved', 'is_online' => false]);

        $this->actingAs($user)->patch(route('drivers.toggle-online', $profile))->assertRedirect();
        $this->assertTrue($profile->fresh()->is_online);

        $this->actingAs($user)->patch(route('drivers.toggle-online', $profile))->assertRedirect();
        $this->assertFalse($profile->fresh()->is_online);
    }

    /**
     * DriverProfile carries HasUserScope, so another user's profile is
     * unreachable via route-model binding at all -- 404, not 403,
     * matching the same fail-closed-at-the-query-layer pattern already
     * established across this codebase (see DriverProfileViewTest).
     */
    public function test_a_driver_cannot_toggle_another_drivers_online_status(): void
    {
        $intruder = User::factory()->withPersonalTeam()->create();
        $owner = User::factory()->create();
        $profile = DriverProfile::factory()->for($owner)->create(['status' => 'approved', 'is_online' => false]);

        $this->actingAs($intruder)
            ->patch(route('drivers.toggle-online', $profile))
            ->assertNotFound();

        $this->assertFalse($profile->fresh()->is_online);
    }

    public function test_a_fleet_owner_cannot_toggle_a_drivers_online_status(): void
    {
        $user = User::factory()->create();
        $profile = DriverProfile::factory()->for($user)->create(['status' => 'approved', 'is_online' => false]);
        $fleetOwner = User::factory()->withPersonalTeam()->create();
        $profile->fleet()->update(['owner_user_id' => $fleetOwner->id]);

        $this->actingAs($fleetOwner)
            ->patch(route('drivers.toggle-online', $profile))
            ->assertNotFound();
    }
}
