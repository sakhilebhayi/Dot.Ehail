<?php

namespace Tests\Feature\Geo;

use App\Domain\Geo\LatLng;
use App\Domain\Geo\Locations\LocationStore;
use App\Domain\Geo\Models\DriverLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGeoTrails;
use Tests\TestCase;

class NearbyDriversTest extends TestCase
{
    use BuildsGeoTrails, RefreshDatabase;

    private LatLng $centre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->centre = new LatLng(-26.1076, 28.0567);
    }

    private function driverAt(float $eastMetres, float $northMetres = 0, int $secondsAgo = 5): User
    {
        $user = User::factory()->create();
        $at = $this->offset($this->centre, $eastMetres, $northMetres);
        DriverLocation::create(['driver_id' => $user->id, 'lat' => $at->lat, 'lng' => $at->lng, 'recorded_at' => now()->subSeconds($secondsAgo), 'received_at' => now()]);

        return $user;
    }

    public function test_finds_fresh_drivers_within_the_radius_nearest_first(): void
    {
        $far = $this->driverAt(2800);
        $near = $this->driverAt(400);
        $diagonal = $this->driverAt(1500, 1500);   // ~2.1 km away: inside the bbox corner, outside the circle at 2 km
        $this->driverAt(0, 3100);                    // outside
        $this->driverAt(100, 0, secondsAgo: 300);    // stale

        $found = (new LocationStore)->nearbyDrivers($this->centre, 3000);

        $this->assertSame([$near->id, $diagonal->id, $far->id], array_map(fn ($d) => $d->driverId, $found));
        $this->assertEqualsWithDelta(400, $found[0]->distanceM, 1);

        $within2km = (new LocationStore)->nearbyDrivers($this->centre, 2000);
        $this->assertSame([$near->id], array_map(fn ($d) => $d->driverId, $within2km), 'the bbox corner is filtered by the exact distance');
    }

    public function test_filters(): void
    {
        $a = $this->driverAt(100);
        $b = $this->driverAt(200);
        $c = $this->driverAt(300);
        $store = new LocationStore;

        $this->assertSame([$b->id, $c->id], array_map(fn ($d) => $d->driverId, $store->nearbyDrivers($this->centre, 1000, ['exclude' => [$a->id]])));
        $this->assertSame([$c->id], array_map(fn ($d) => $d->driverId, $store->nearbyDrivers($this->centre, 1000, ['only' => [$c->id]])));
        $this->assertCount(2, $store->nearbyDrivers($this->centre, 1000, ['limit' => 2]));
        $this->assertCount(3, $store->nearbyDrivers($this->centre, 1000, ['max_age_seconds' => 3600]));
    }

    public function test_latest_returns_rows_keyed_by_driver(): void
    {
        $a = $this->driverAt(100);
        $store = new LocationStore;

        $this->assertTrue($store->latest([$a->id, 999])->has($a->id));
        $this->assertCount(0, $store->latest([]));
    }
}
