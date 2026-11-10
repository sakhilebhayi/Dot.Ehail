<?php

namespace Tests\Unit\Geo;

use App\Domain\Geo\Distance\DistanceMeter;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Locations\LocationStore;
use App\Domain\Geo\Routing\Drivers\NullRoutingDriver;
use App\Domain\Geo\Routing\RoutePlanner;
use App\Domain\Geo\Routing\RoutingDriver;
use App\Domain\Geo\Support\CircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsGeoTrails;
use Tests\TestCase;

class DistanceMeterTest extends TestCase
{
    use BuildsGeoTrails, RefreshDatabase;

    private LatLng $origin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->origin = new LatLng(-26.1076, 28.0567);
    }

    private function meter(?RoutingDriver $driver = null): DistanceMeter
    {
        return new DistanceMeter(new LocationStore, new RoutePlanner($driver ?? new NullRoutingDriver, new CircuitBreaker(Cache::store())));
    }

    public function test_a_clean_trail_measures_its_length(): void
    {
        // 51 points, 40 m apart: 2,000 m.
        $result = $this->meter()->measure($this->eastboundTrail($this->origin, 51));

        $this->assertEqualsWithDelta(2000, $result->travelledM, 5);
        $this->assertSame($result->travelledM, $result->billableM);
        $this->assertSame('gps', $result->method);
        $this->assertSame('good', $result->quality);
        $this->assertSame([], $result->flags);
    }

    public function test_a_noisy_trail_stays_close_to_the_true_distance(): void
    {
        $result = $this->meter()->measure($this->eastboundTrail($this->origin, 51, noise: 6));

        // Zig-zag noise adds a little length; it must stay within 5% of the 2,000 m driven.
        $this->assertEqualsWithDelta(2000, $result->travelledM, 100);
    }

    public function test_standing_still_with_jitter_adds_almost_nothing(): void
    {
        // Five minutes parked, a fix every 4 s scattered +-7 m around one spot.
        $result = $this->meter()->measure($this->eastboundTrail($this->origin, 75, speed: 0, noise: 7, accuracy: 10));

        $this->assertLessThan(30, $result->travelledM);
    }

    public function test_slow_traffic_is_not_lost_as_jitter(): void
    {
        // 1 m/s for 400 s: each step (4 m) is below the jitter floor but the progress accumulates from the anchor.
        $result = $this->meter()->measure($this->eastboundTrail($this->origin, 101, speed: 1));

        $this->assertEqualsWithDelta(400, $result->travelledM, 12);
    }

    public function test_a_gps_jump_is_dropped_and_flagged(): void
    {
        $trail = $this->eastboundTrail($this->origin, 26);
        // Point 10 teleports 5 km north, then the trail continues normally.
        $trail[10] = $this->tripPoint($this->offset($this->origin, 400, 5000), $trail[10]->recorded_at);

        $result = $this->meter()->measure($trail);

        $this->assertEqualsWithDelta(1000, $result->travelledM, 5);
        $this->assertContains('speed_outliers', $result->flags);
        $this->assertSame(1, $result->pointsDropped);
    }

    public function test_inaccurate_fixes_are_ignored(): void
    {
        $trail = $this->eastboundTrail($this->origin, 26);
        $trail[5] = $this->tripPoint($this->offset($this->origin, 200, 300), $trail[5]->recorded_at, accuracy: 400);

        $result = $this->meter()->measure($trail);

        $this->assertEqualsWithDelta(1000, $result->travelledM, 5);
        $this->assertContains('inaccurate_points', $result->flags);
    }

    public function test_a_tunnel_gap_is_bridged_with_a_road_route(): void
    {
        $start = CarbonImmutable::parse('2026-11-10 08:00:00');
        $before = $this->eastboundTrail($this->origin, 11, start: $start);         // 0..400 m
        $exit = $this->offset($this->origin, 1900);                                 // 1.5 km later, 120 s on
        $after = $this->eastboundTrail($exit, 11, start: $start->addSeconds(160));  // 1900..2300 m

        $driver = $this->stubRoutingDriver(1800);
        $result = $this->meter($driver)->measure([...$before, ...$after]);

        $this->assertSame(1, $driver->calls);
        $this->assertSame('gps_with_route_gaps', $result->method);
        $this->assertSame(1, $result->gapsRerouted);
        $this->assertEqualsWithDelta(2300, $result->travelledM, 5, 'travelled bridges the gap in a straight line');
        $this->assertEqualsWithDelta(2600, $result->billableM, 5, 'billable uses the 1,800 m road instead of the 1,500 m line');
        $this->assertSame('good', $result->quality);
    }

    public function test_a_gap_without_routing_stays_straight_and_is_flagged(): void
    {
        $start = CarbonImmutable::parse('2026-11-10 08:00:00');
        $before = $this->eastboundTrail($this->origin, 11, start: $start);
        $after = $this->eastboundTrail($this->offset($this->origin, 1900), 11, start: $start->addSeconds(160));

        $result = $this->meter()->measure([...$before, ...$after]);

        $this->assertSame('gps_with_straight_gaps', $result->method);
        $this->assertContains('unresolved_gap', $result->flags);
        $this->assertSame('degraded', $result->quality);
        $this->assertSame($result->travelledM, $result->billableM);
    }

    public function test_a_road_route_shorter_than_the_line_never_shrinks_the_distance(): void
    {
        $start = CarbonImmutable::parse('2026-11-10 08:00:00');
        $trail = [...$this->eastboundTrail($this->origin, 2, start: $start), ...$this->eastboundTrail($this->offset($this->origin, 1540), 2, start: $start->addSeconds(120))];

        $result = $this->meter($this->stubRoutingDriver(100))->measure($trail);

        $this->assertGreaterThanOrEqual($result->travelledM, $result->billableM);
    }

    public function test_too_few_points_is_insufficient(): void
    {
        $result = $this->meter()->measure($this->eastboundTrail($this->origin, 1));

        $this->assertSame('no_data', $result->method);
        $this->assertSame('insufficient', $result->quality);
        $this->assertSame(0, $result->billableM);
    }
}
