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
            'user_id'        => $driver->id,
            'license_number' => 'LIC-200',
            'id_number'      => 'ID-200',
            'status'         => 'approved',
        ]);

        $this->get(route('drivers.show', $profile))->assertRedirect('/login');
    }

    public function test_driver_can_view_their_own_profile_and_ride_history(): void
    {
        $driver = User::factory()->withPersonalTeam()->create();

        $profile = DriverProfile::create([
            'user_id'        => $driver->id,
            'license_number' => 'LIC-201',
            'id_number'      => 'ID-201',
            'status'         => 'approved',
            'total_rides'    => 3,
        ]);

        $passenger = User::factory()->create();
        Ride::create([
            'passenger_id'    => $passenger->id,
            'driver_id'       => $driver->id,
            'pickup_address'  => '5 Church Street',
            'dropoff_address' => '9 Bree Street',
            'status'          => 'completed',
            'final_fare'      => 120,
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
     */
    public function test_a_different_authenticated_user_cannot_view_someone_elses_driver_profile(): void
    {
        $otherUser = User::factory()->withPersonalTeam()->create();
        $driver    = User::factory()->create();

        $profile = DriverProfile::create([
            'user_id'        => $driver->id,
            'license_number' => 'LIC-202',
            'id_number'      => 'ID-202',
            'status'         => 'approved',
        ]);

        $this->actingAs($otherUser)
            ->get(route('drivers.show', $profile))
            ->assertForbidden();
    }
}
