<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Fleet;
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

    public function test_authenticated_user_with_no_current_team_can_view_the_dashboard(): void
    {
        // Regression test: navigation-menu.blade.php (rendered on every
        // authenticated page via layouts/app.blade.php's
        // @livewire('navigation-menu')) used to dereference
        // Auth::user()->currentTeam->name/->id unguarded. currentTeam is
        // genuinely null for a user with no current_team_id — e.g. a user
        // authenticated via EcosystemAuthController (SSO login that never
        // runs CreateNewUser's personal-team bootstrap) or a team owner
        // whose only team was deleted (DeleteTeam -> Team::purge() does not
        // null out former members' current_team_id). Previously this threw
        // a UrlGenerationException from route('teams.show', null) because
        // isset() on a null value is false, so the {team} route parameter
        // was never filled in.
        $user = User::factory()->create(['current_team_id' => null]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('No Team');
    }

    public function test_dashboard_reflects_real_ride_and_driver_counts(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $passenger = User::factory()->create();
        $driver = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Test Fleet', 'owner_user_id' => User::factory()->create()->id]);

        DriverProfile::create([
            'user_id' => $driver->id,
            'fleet_id' => $fleet->id,
            'license_number' => 'LIC-100',
            'id_number' => 'ID-100',
            'status' => 'approved',
            'is_online' => true,
        ]);

        Ride::create([
            'passenger_id' => $passenger->id,
            'driver_id' => $driver->id,
            'pickup_address' => '1 Main Street',
            'dropoff_address' => '2 Second Avenue',
            'status' => 'completed',
            'final_fare' => 85.50,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('1 Main Street');
        $response->assertSee('2 Second Avenue');
    }
}
