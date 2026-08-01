<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_the_dashboard(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewIs('dashboard')
            ->assertSee('Ehail Operations Dashboard');
    }

    public function test_dashboard_shows_empty_state_when_there_are_no_rides(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('No rides recorded yet.');
    }

    public function test_dashboard_reflects_real_ride_and_driver_counts(): void
    {
        $user      = User::factory()->withPersonalTeam()->create();
        $passenger = User::factory()->create();
        $driver    = User::factory()->create();

        DriverProfile::create([
            'user_id'        => $driver->id,
            'license_number' => 'LIC-100',
            'id_number'      => 'ID-100',
            'status'         => 'approved',
            'is_online'      => true,
        ]);

        Ride::create([
            'passenger_id'    => $passenger->id,
            'driver_id'       => $driver->id,
            'pickup_address'  => '1 Main Street',
            'dropoff_address' => '2 Second Avenue',
            'status'          => 'completed',
            'final_fare'      => 85.50,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('1 Main Street');
        $response->assertSee('2 Second Avenue');
    }
}
