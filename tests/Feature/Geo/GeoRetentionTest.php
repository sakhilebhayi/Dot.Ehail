<?php

namespace Tests\Feature\Geo;

use App\Domain\Geo\Distance\DistanceMeter;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Locations\TrailRetentionHold;
use App\Domain\Geo\Models\DriverLocation;
use App\Domain\Geo\Models\RideRoute;
use App\Domain\Geo\Models\RouteCacheEntry;
use App\Domain\Geo\Models\TripPoint;
use App\Models\Ride;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGeoTrails;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class GeoRetentionTest extends TestCase
{
    use BuildsGeoTrails, CreatesEhailFixtures, RefreshDatabase;

    private function rideWithTrail(int $daysAgo): Ride
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['driver_id' => $driver->id, 'status' => 'completed']);

        foreach ($this->eastboundTrail(new LatLng(-26.1, 28.05), 26, start: now()->subDays($daysAgo)->toImmutable()) as $point) {
            $point->ride_id = $ride->id;
            $point->driver_id = $driver->id;
            $point->save();
        }

        return $ride;
    }

    public function test_old_trails_are_summarised_then_deleted_and_recent_ones_kept(): void
    {
        $old = $this->rideWithTrail(91);
        $recent = $this->rideWithTrail(10);

        $this->artisan('ehail:geo-purge')->expectsOutputToContain('1 trips summarised (26 points)')->assertSuccessful();

        $this->assertSame(0, TripPoint::where('ride_id', $old->id)->count());
        $this->assertSame(26, TripPoint::where('ride_id', $recent->id)->count());

        $summary = RideRoute::where('ride_id', $old->id)->sole();
        $this->assertEqualsWithDelta(1000, $summary->travelled_m, 5);
        $this->assertNotNull($summary->trail_purged_at);
        $this->assertSame('gps', $summary->distance_method);

        // The meter answers from the summary once the raw points are gone.
        $measured = app(DistanceMeter::class)->actual($old);
        $this->assertSame('stored_summary', $measured->method);
        $this->assertSame($summary->billable_m, $measured->billableM);
    }

    public function test_a_ride_held_by_safety_keeps_its_trail(): void
    {
        $held = $this->rideWithTrail(120);
        $this->app->instance(TrailRetentionHold::class, new class($held->id) implements TrailRetentionHold
        {
            public function __construct(private int $id) {}

            public function held(array $rideIds): array
            {
                return array_values(array_intersect($rideIds, [$this->id]));
            }
        });

        $this->artisan('ehail:geo-purge')->expectsOutputToContain('1 held by safety')->assertSuccessful();
        $this->assertSame(26, TripPoint::where('ride_id', $held->id)->count());
    }

    public function test_stale_driver_locations_and_expired_routes_are_deleted(): void
    {
        $stale = $this->makeApprovedDriver();
        $fresh = $this->makeApprovedDriver();
        DriverLocation::create(['driver_id' => $stale->id, 'lat' => -26, 'lng' => 28, 'recorded_at' => now()->subDays(8), 'received_at' => now()->subDays(8)]);
        DriverLocation::create(['driver_id' => $fresh->id, 'lat' => -26, 'lng' => 28, 'recorded_at' => now(), 'received_at' => now()]);
        RouteCacheEntry::create(['cache_key' => 'a', 'provider' => 'osrm', 'profile' => 'driving', 'distance_m' => 1, 'duration_s' => 1, 'polyline' => '', 'expires_at' => now()->subMinute()]);
        RouteCacheEntry::create(['cache_key' => 'b', 'provider' => 'osrm', 'profile' => 'driving', 'distance_m' => 1, 'duration_s' => 1, 'polyline' => '', 'expires_at' => now()->addMinute()]);

        $this->artisan('ehail:geo-purge --dry-run')->expectsOutputToContain('[dry run]')->assertSuccessful();
        $this->assertSame(2, DriverLocation::count());

        $this->artisan('ehail:geo-purge')->assertSuccessful();
        $this->assertSame([$fresh->id], DriverLocation::pluck('driver_id')->all());
        $this->assertSame(['b'], RouteCacheEntry::pluck('cache_key')->all());
    }

    public function test_the_purge_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'ehail:geo-purge'));

        $this->assertCount(1, $events);
        $this->assertSame('40 2 * * *', $events->first()->expression);
    }
}
