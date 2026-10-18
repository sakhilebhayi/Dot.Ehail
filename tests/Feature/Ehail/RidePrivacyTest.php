<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A ride belongs to its passenger, its assigned driver, that driver's fleet
 * owner (approved fleets only), and platform operators. Nobody else may see
 * it in a list, on its detail page, or through dashboard aggregates.
 */
class RidePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $operator = User::factory()->withPersonalTeam()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        return $operator;
    }

    private function approvedDriver(?Fleet $fleet = null): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $profile = DriverProfile::factory()->for($user)->create([
            'status' => 'approved',
            'is_online' => true,
            'fleet_id' => ($fleet ?? Fleet::factory()->create(['status' => 'approved']))->id,
        ]);
        Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true]);

        return $user;
    }

    private function ride(User $passenger, ?User $driver, array $overrides = []): Ride
    {
        return Ride::create(array_merge([
            'passenger_id' => $passenger->id,
            'driver_id' => $driver?->id,
            'pickup_address' => 'Secret Pickup '.$passenger->id,
            'dropoff_address' => 'Secret Dropoff '.$passenger->id,
            'status' => $driver ? 'accepted' : 'requested',
            'vehicle_type' => 'standard',
            'estimated_fare' => 80,
        ], $overrides));
    }

    // ─── Detail page ─────────────────────────────────────────────────────

    public function test_an_unrelated_user_cannot_view_another_riders_ride(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $stranger = User::factory()->withPersonalTeam()->create();
        $ride = $this->ride($passenger, $this->approvedDriver());

        $this->actingAs($stranger)->get(route('rides.show', $ride))->assertForbidden();
    }

    public function test_passenger_driver_fleet_owner_and_operator_can_view_the_ride(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $fleet = Fleet::factory()->create(['status' => 'approved', 'owner_user_id' => $owner->id]);
        $driver = $this->approvedDriver($fleet);
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->ride($passenger, $driver);

        foreach ([$passenger, $driver, $owner, $this->operator()] as $allowed) {
            $this->actingAs($allowed)->get(route('rides.show', $ride))->assertOk();
        }
    }

    public function test_the_owner_of_a_different_fleet_cannot_view_the_ride(): void
    {
        $driver = $this->approvedDriver();
        $ride = $this->ride(User::factory()->create(), $driver);

        $otherOwner = User::factory()->withPersonalTeam()->create();
        Fleet::factory()->create(['status' => 'approved', 'owner_user_id' => $otherOwner->id]);

        $this->actingAs($otherOwner)->get(route('rides.show', $ride))->assertForbidden();
    }

    public function test_the_owner_of_an_unapproved_fleet_cannot_view_its_drivers_rides(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $fleet = Fleet::factory()->create(['status' => 'pending', 'owner_user_id' => $owner->id]);
        $ride = $this->ride(User::factory()->create(), $this->approvedDriver($fleet));

        $this->actingAs($owner)->get(route('rides.show', $ride))->assertForbidden();
    }

    // ─── Listing ─────────────────────────────────────────────────────────

    public function test_the_ride_list_only_contains_rides_the_user_is_a_party_to(): void
    {
        $alice = User::factory()->withPersonalTeam()->create();
        $bob = User::factory()->withPersonalTeam()->create();
        $aliceRide = $this->ride($alice, null);
        $bobRide = $this->ride($bob, null);

        $this->actingAs($alice)
            ->get(route('rides.index'))
            ->assertOk()
            ->assertSee($aliceRide->pickup_address)
            ->assertDontSee($bobRide->pickup_address);
    }

    public function test_searching_cannot_surface_another_riders_ride(): void
    {
        $alice = User::factory()->withPersonalTeam()->create();
        $bob = User::factory()->withPersonalTeam()->create(['name' => 'Bobby Tables']);
        $bobRide = $this->ride($bob, null);

        $this->actingAs($alice)
            ->get(route('rides.index', ['q' => 'Bobby']))
            ->assertOk()
            ->assertDontSee($bobRide->pickup_address);

        $this->actingAs($alice)
            ->get(route('rides.index', ['q' => 'Secret']))
            ->assertOk()
            ->assertDontSee($bobRide->pickup_address);
    }

    public function test_a_driver_sees_rides_they_drive_and_a_fleet_owner_sees_their_drivers_rides(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $fleet = Fleet::factory()->create(['status' => 'approved', 'owner_user_id' => $owner->id]);
        $driver = $this->approvedDriver($fleet);
        $ride = $this->ride(User::factory()->create(), $driver);
        $unrelated = $this->ride(User::factory()->create(), $this->approvedDriver());

        foreach ([$driver, $owner] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('rides.index'))
                ->assertOk()
                ->assertSee($ride->pickup_address)
                ->assertDontSee($unrelated->pickup_address);
        }
    }

    public function test_an_operator_sees_every_ride(): void
    {
        $a = $this->ride(User::factory()->create(), null);
        $b = $this->ride(User::factory()->create(), null);

        $this->actingAs($this->operator())
            ->get(route('rides.index'))
            ->assertOk()
            ->assertSee($a->pickup_address)
            ->assertSee($b->pickup_address);
    }

    // ─── Dashboard ───────────────────────────────────────────────────────

    public function test_a_regular_user_dashboard_hides_platform_revenue_driver_counts_and_foreign_rides(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->create();
        $driver = $this->approvedDriver();
        $foreign = $this->ride($other, $driver, ['status' => 'completed', 'final_fare' => 1234.56]);

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        $response->assertDontSee('Total Revenue');
        $response->assertDontSee('1,234.56');
        $response->assertDontSee('Total Drivers');
        $response->assertDontSee('Driver Fleet');
        $response->assertDontSee($foreign->pickup_address);
        $response->assertViewHas('totalRides', 0);
        $response->assertViewHas('showFleetStats', false);
    }

    public function test_an_operator_dashboard_shows_platform_wide_revenue_and_driver_counts(): void
    {
        $driver = $this->approvedDriver();
        $this->ride(User::factory()->create(), $driver, ['status' => 'completed', 'final_fare' => 1234.56]);

        $response = $this->actingAs($this->operator())->get('/dashboard')->assertOk();

        $response->assertSee('Total Revenue');
        $response->assertSee('1,234.56');
        $response->assertViewHas('totalDrivers', 1);
        $response->assertViewHas('totalRides', 1);
    }

    public function test_a_fleet_owner_dashboard_is_scoped_to_their_own_fleet(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $fleet = Fleet::factory()->create(['status' => 'approved', 'owner_user_id' => $owner->id]);
        $ownDriver = $this->approvedDriver($fleet);
        $foreignDriver = $this->approvedDriver();

        $this->ride(User::factory()->create(), $ownDriver, ['status' => 'completed', 'final_fare' => 100.00]);
        $this->ride(User::factory()->create(), $foreignDriver, ['status' => 'completed', 'final_fare' => 7000.00]);

        $response = $this->actingAs($owner)->get('/dashboard')->assertOk();

        $response->assertViewHas('totalDrivers', 1);
        $response->assertViewHas('totalRides', 1);
        $response->assertViewHas('totalRevenue', fn ($revenue) => (float) $revenue === 100.0);
        $response->assertDontSee('7,000.00');
    }

    public function test_dashboard_revenue_ignores_completed_rides_with_a_null_final_fare(): void
    {
        $driver = $this->approvedDriver();
        $this->ride(User::factory()->create(), $driver, ['status' => 'completed', 'final_fare' => null]);
        $this->ride(User::factory()->create(), $driver, ['status' => 'completed', 'final_fare' => 55.50]);

        $this->actingAs($this->operator())
            ->get('/dashboard')
            ->assertOk()
            ->assertViewHas('totalRevenue', fn ($revenue) => (float) $revenue === 55.5);
    }

    public function test_dashboard_revenue_is_zero_when_every_completed_fare_is_null(): void
    {
        $this->ride(User::factory()->create(), $this->approvedDriver(), ['status' => 'completed', 'final_fare' => null]);

        $this->actingAs($this->operator())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('R 0.00');
    }

    // ─── Available queue ─────────────────────────────────────────────────

    public function test_the_available_queue_shows_no_passenger_identity(): void
    {
        $driver = $this->approvedDriver();
        $passenger = User::factory()->create(['name' => 'Zelda Passenger', 'email' => 'zelda.passenger@example.test']);
        $ride = $this->ride($passenger, null);

        $response = $this->actingAs($driver)->get(route('rides.available'))
            ->assertOk()
            ->assertSee($ride->pickup_address)
            ->assertDontSee('Zelda Passenger')
            ->assertDontSee('zelda.passenger@example.test');

        // The passenger relation must not even be loaded into the view data.
        $response->assertViewHas('rides', fn ($rides) => $rides->every(fn ($r) => ! $r->relationLoaded('passenger')));
    }

    public function test_the_available_queue_stays_closed_to_non_driver_and_pending_driver(): void
    {
        $ride = $this->ride(User::factory()->create(), null);

        $this->actingAs(User::factory()->withPersonalTeam()->create())
            ->get(route('rides.available'))
            ->assertOk()
            ->assertDontSee($ride->pickup_address);

        $pending = User::factory()->withPersonalTeam()->create();
        DriverProfile::factory()->for($pending)->create(['status' => 'pending', 'is_online' => true]);

        $this->actingAs($pending)
            ->get(route('rides.available'))
            ->assertOk()
            ->assertDontSee($ride->pickup_address);
    }
}
