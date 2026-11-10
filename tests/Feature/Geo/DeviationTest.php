<?php

namespace Tests\Feature\Geo;

use App\Domain\Geo\Deviation\DeviationDetector;
use App\Domain\Geo\Deviation\DeviationMonitor;
use App\Domain\Geo\Events\RouteDeviationDetected;
use App\Domain\Geo\Events\UnusualStopDetected;
use App\Domain\Geo\Geometry\Polyline;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Models\TripPoint;
use App\Domain\Geo\Routing\RideRoutePlanner;
use App\Domain\Geo\Routing\RouteResult;
use App\Models\Ride;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsGeoTrails;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class DeviationTest extends TestCase
{
    use BuildsGeoTrails, CreatesEhailFixtures, RefreshDatabase;

    private LatLng $origin;

    private Ride $ride;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->origin = new LatLng(-26.1076, 28.0567);
        $destination = $this->offset($this->origin, 5000);
        $driver = $this->makeApprovedDriver();
        $this->ride = $this->makeRide([
            'driver_id' => $driver->id, 'status' => 'in_progress',
            'pickup_lat' => $this->origin->lat, 'pickup_lng' => $this->origin->lng,
            'dropoff_lat' => $destination->lat, 'dropoff_lng' => $destination->lng,
        ]);
    }

    private function plan(): void
    {
        $line = Polyline::encode([$this->origin, $this->offset($this->origin, 2500), $this->offset($this->origin, 5000)]);
        app(RideRoutePlanner::class)->attach($this->ride, new RouteResult(5000, 600, $line, 'stub'));
    }

    /**
     * @param  list<array{0: float, 1: float}>  $offsets  [east, north] metres, one fix every $interval seconds ending now
     */
    private function trail(array $offsets, int $interval = 4): void
    {
        $count = count($offsets);

        foreach ($offsets as $i => [$east, $north]) {
            $at = $this->offset($this->origin, $east, $north);
            $when = CarbonImmutable::now()->subSeconds(($count - 1 - $i) * $interval);
            TripPoint::create([
                'ride_id' => $this->ride->id, 'driver_id' => $this->ride->driver_id, 'lat' => $at->lat, 'lng' => $at->lng,
                'accuracy_m' => 8, 'recorded_at' => $when, 'received_at' => $when,
            ]);
        }
    }

    public function test_on_route_is_quiet(): void
    {
        $this->plan();
        $this->trail([[0, 0], [40, 10], [80, -10], [120, 20], [160, 0]]);

        $this->assertNull(app(DeviationDetector::class)->check($this->ride));
    }

    public function test_consecutive_samples_far_off_the_route_are_a_deviation(): void
    {
        $this->plan();
        $this->trail([[1000, 0], [1040, 200], [1060, 450], [1080, 600], [1100, 800]]);

        $deviation = app(DeviationDetector::class)->check($this->ride);

        $this->assertSame('route_deviation', $deviation->kind);
        $this->assertEqualsWithDelta(800, $deviation->metres, 5);
        $this->assertSame($this->ride->id, $deviation->rideId);
        $this->assertSame(300, $deviation->context['threshold_m']);
    }

    public function test_one_stray_sample_is_not_a_deviation(): void
    {
        $this->plan();
        $this->trail([[1000, 0], [1040, 0], [1060, 700], [1080, 0], [1100, 0]]);

        $this->assertNull(app(DeviationDetector::class)->check($this->ride));
    }

    public function test_the_monitor_raises_the_event_once_per_cooldown(): void
    {
        Event::fake([RouteDeviationDetected::class, UnusualStopDetected::class]);
        $this->plan();
        $this->trail([[1000, 600], [1040, 650], [1060, 700]]);
        $monitor = app(DeviationMonitor::class);

        $monitor->evaluate($this->ride);
        $monitor->evaluate($this->ride);

        Event::assertDispatchedTimes(RouteDeviationDetected::class, 1);
        Event::assertDispatched(RouteDeviationDetected::class, fn ($e) => $e->rideId === $this->ride->id && $e->offRouteMetres > 600);
    }

    public function test_a_long_stop_away_from_the_dropoff_is_unusual(): void
    {
        Event::fake([UnusualStopDetected::class]);
        $this->plan();
        // Six minutes standing on the route, jittering a few metres.
        $this->trail(array_map(fn ($i) => [2500 + ($i % 3) * 4, ($i % 2) * 5], range(0, 90)));

        $deviation = app(DeviationMonitor::class)->evaluate($this->ride);

        $this->assertSame('unusual_stop', $deviation->kind);
        $this->assertGreaterThanOrEqual(300, $deviation->seconds);
        Event::assertDispatched(UnusualStopDetected::class, fn ($e) => $e->stoppedSeconds >= 300 && $e->context['distance_to_dropoff_m'] > 2000);
    }

    public function test_a_short_stop_or_a_stop_at_the_dropoff_is_normal(): void
    {
        $this->plan();
        $this->trail(array_map(fn ($i) => [2500, 0], range(0, 30))); // two minutes
        $this->assertNull(app(DeviationDetector::class)->check($this->ride));

        TripPoint::query()->delete();
        $this->trail(array_map(fn ($i) => [4950, 0], range(0, 90)));  // six minutes at the drop-off
        $this->assertNull(app(DeviationDetector::class)->check($this->ride));
    }

    public function test_without_a_planned_route_only_stops_are_checked(): void
    {
        // No route attached and routing is 'none': deviation from a route cannot be judged, and is not claimed.
        $this->trail([[1000, 600], [1040, 650], [1060, 700]]);
        $this->assertNull(app(DeviationDetector::class)->check($this->ride));

        TripPoint::query()->delete();
        $this->trail(array_map(fn ($i) => [1000, 600], range(0, 90)));
        $this->assertSame('unusual_stop', app(DeviationDetector::class)->check($this->ride)->kind);
    }

    public function test_rides_not_in_progress_are_never_checked(): void
    {
        $this->plan();
        $this->trail([[1000, 600], [1040, 650], [1060, 700]]);
        $this->ride->update(['status' => 'completed']);

        $this->assertNull(app(DeviationDetector::class)->check($this->ride));
    }

    public function test_ingest_runs_the_monitor_during_a_trip(): void
    {
        Event::fake([RouteDeviationDetected::class]);
        $this->plan();
        $this->trail([[1000, 600], [1040, 650]]);
        $at = $this->offset($this->origin, 1080, 700);

        $this->actingAs($this->ride->driver)->postJson(route('geo.driver.location'), ['samples' => [[
            'lat' => $at->lat, 'lng' => $at->lng, 'accuracy' => 8, 'recorded_at' => now()->addSecond()->getTimestampMs(),
        ]]])->assertOk();

        Event::assertDispatched(RouteDeviationDetected::class);
    }
}
