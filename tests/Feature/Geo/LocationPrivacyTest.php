<?php

namespace Tests\Feature\Geo;

use App\Domain\Geo\Models\DriverLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

/**
 * POPIA: a passenger sees only their assigned driver, only during the active ride; nobody else sees anyone.
 */
class LocationPrivacyTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    private User $driver;

    private User $passenger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = $this->makeApprovedDriver();
        $this->passenger = User::factory()->withPersonalTeam()->create();
        DriverLocation::create([
            'driver_id' => $this->driver->id, 'lat' => -26.1076, 'lng' => 28.0567, 'accuracy_m' => 7.6, 'heading_deg' => 91.4,
            'recorded_at' => now()->subSeconds(3), 'received_at' => now(),
        ]);
    }

    private function url($ride): string
    {
        return route('geo.rides.driver-location', $ride);
    }

    public function test_the_passenger_sees_the_assigned_driver_while_the_ride_is_active(): void
    {
        foreach (['accepted', 'en_route', 'arrived', 'in_progress'] as $status) {
            $ride = $this->makeRide(['driver_id' => $this->driver->id, 'status' => $status], $this->passenger);

            $this->actingAs($this->passenger)->getJson($this->url($ride))
                ->assertOk()
                ->assertJsonPath('driver_location.lat', -26.1076)
                ->assertJsonPath('driver_location.accuracy_m', 8)
                ->assertJsonPath('driver_location.heading_deg', 91)
                ->assertJsonPath('driver_location.age_seconds', 3)
                ->assertJsonMissingPath('driver_location.driver_id');

            // One active ride per passenger and driver (dispatch D-402): end this one before the next status.
            $ride->update(['status' => 'completed']);
        }
    }

    public function test_the_passenger_loses_sight_of_the_driver_once_the_ride_ends_or_before_assignment(): void
    {
        foreach (['completed', 'cancelled', 'expired'] as $status) {
            $ride = $this->makeRide(['driver_id' => $this->driver->id, 'status' => $status], $this->passenger);
            $this->actingAs($this->passenger)->getJson($this->url($ride))->assertForbidden()->assertJsonPath('reason', 'not_tracking');
        }

        $unassigned = $this->makeRide(['status' => 'requested'], $this->passenger);
        $this->actingAs($this->passenger)->getJson($this->url($unassigned))->assertForbidden();
    }

    public function test_other_passengers_and_other_drivers_get_not_found(): void
    {
        $ride = $this->makeRide(['driver_id' => $this->driver->id, 'status' => 'in_progress'], $this->passenger);

        $this->actingAs(User::factory()->withPersonalTeam()->create())->getJson($this->url($ride))->assertNotFound();
        $this->actingAs($this->makeApprovedDriver())->getJson($this->url($ride))->assertNotFound();
    }

    public function test_the_driver_and_operators_may_read_it(): void
    {
        $ride = $this->makeRide(['driver_id' => $this->driver->id, 'status' => 'completed'], $this->passenger);

        $this->actingAs($this->driver)->getJson($this->url($ride))->assertOk();
        $this->actingAs($this->makeOperator())->getJson($this->url($ride))->assertOk();
    }

    public function test_no_geo_endpoint_exposes_or_accepts_a_passenger_position(): void
    {
        $geoRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'geo/'));

        $this->assertNotEmpty($geoRoutes);
        $this->assertSame([], $geoRoutes->map->uri()->filter(fn ($uri) => str_contains($uri, 'passenger'))->values()->all());

        // The only position writer is the driver ingest, which refuses non-drivers (DriverLocationIngestTest).
        $writers = $geoRoutes->filter(fn ($r) => in_array('POST', $r->methods(), true))->map->uri()->values()->all();
        $this->assertSame(['geo/driver/location'], $writers);
    }
}
