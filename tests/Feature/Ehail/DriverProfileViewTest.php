<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverProfileViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_a_driver_profile(): void
    {
        $driver = User::factory()->create();

        $profile = DriverProfile::create([
            'user_id' => $driver->id,
            'license_number' => 'LIC-200',
            'id_number' => 'ID-200',
            'status' => 'approved',
        ]);

        $this->get(route('drivers.show', $profile))->assertRedirect('/login');
    }

    public function test_driver_can_view_their_own_profile_and_ride_history(): void
    {
        $driver = User::factory()->withPersonalTeam()->create();

        $profile = DriverProfile::create([
            'user_id' => $driver->id,
            'license_number' => 'LIC-201',
            'id_number' => 'ID-201',
            'status' => 'approved',
            'total_rides' => 3,
        ]);

        $passenger = User::factory()->create();
        Ride::create([
            'passenger_id' => $passenger->id,
            'driver_id' => $driver->id,
            'pickup_address' => '5 Church Street',
            'dropoff_address' => '9 Bree Street',
            'status' => 'completed',
            'final_fare' => 120,
        ]);

        $this->actingAs($driver)
            ->get(route('drivers.show', $profile))
            ->assertOk()
            ->assertViewIs('drivers.show')
            ->assertSee('LIC-201')
            ->assertSee('5 Church Street');
    }

    /**
     * This is the concrete gap DriverProfilePolicy fixes: before it existed,
     * any authenticated user could load /drivers/{id} for any driver and see
     * their full ride history and identifiers, regardless of ownership.
     *
     * Since DriverProfile gained HasUserScope (app/Models/Concerns/HasUserScope.php),
     * this now 404s instead of 403ing: implicit route-model binding is
     * scoped too, so another user's profile row is invisible to the query
     * entirely, and Laravel never gets as far as resolving a model to hand
     * to the Gate/Policy check. This is a stronger, fail-closed posture than
     * before (an attacker can no longer even distinguish "exists but I can't
     * see it" from "doesn't exist"), the same real behavior change the
     * Dot.Finance pilot found when it applied the analogous HasUserScope to
     * its own models.
     */
    public function test_a_different_authenticated_user_cannot_view_someone_elses_driver_profile(): void
    {
        $otherUser = User::factory()->withPersonalTeam()->create();
        $driver = User::factory()->create();

        $profile = DriverProfile::create([
            'user_id' => $driver->id,
            'license_number' => 'LIC-202',
            'id_number' => 'ID-202',
            'status' => 'approved',
        ]);

        $this->actingAs($otherUser)
            ->get(route('drivers.show', $profile))
            ->assertNotFound();
    }

    /**
     * Proves the model-level scope alone blocks cross-user reads, with no
     * Policy/Gate check anywhere in the path -- mirroring the regression
     * test the Dot.Finance pilot added for its own HasUserScope
     * (test_scope_alone_blocks_cross_user_access_even_without_a_policy_check).
     * A plain Eloquent lookup for another user's driver profile must come
     * back empty purely because of the global scope.
     */
    public function test_scope_alone_blocks_cross_user_access_even_without_a_policy_check(): void
    {
        $otherUser = User::factory()->withPersonalTeam()->create();
        $driver = User::factory()->create();

        $profile = DriverProfile::create([
            'user_id' => $driver->id,
            'license_number' => 'LIC-203',
            'id_number' => 'ID-203',
            'status' => 'approved',
        ]);

        $this->actingAs($otherUser);

        $this->assertNull(DriverProfile::find($profile->id));
        $this->assertSame(0, DriverProfile::count());

        $this->actingAs($driver);

        $this->assertNotNull(DriverProfile::find($profile->id));
        $this->assertSame(1, DriverProfile::count());
    }
}
