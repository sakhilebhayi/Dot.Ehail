<?php

namespace App\Domain\Geo\Deviation;

use App\Domain\Geo\Geometry\Haversine;
use App\Domain\Geo\Geometry\PathDistance;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Locations\RideTracking;
use App\Domain\Geo\Models\TripPoint;
use App\Domain\Geo\Routing\RideRoutePlanner;
use App\Models\Ride;
use Carbon\CarbonImmutable;

/**
 * Looks at the recent trail of a trip in progress for two things a person should check:
 *  - route deviation: the last N good samples are all further than threshold (+ their accuracy) from the planned
 *    road route;
 *  - unusual stop: stationary within a small radius for longer than stop_seconds, away from the drop-off.
 * It only reads; DeviationMonitor raises the events.
 */
class DeviationDetector
{
    public function __construct(private RideRoutePlanner $plans) {}

    public function check(Ride $ride): ?Deviation
    {
        if (! RideTracking::isOnTrip($ride)) {
            return null;
        }

        $config = config('ehail-geo.deviation');
        $maxAccuracy = (float) config('ehail-geo.distance.max_point_accuracy_m', 50);

        $recent = TripPoint::query()
            ->where('ride_id', $ride->id)
            ->where('recorded_at', '>=', now()->subSeconds((int) $config['stop_seconds'] + 120))
            ->orderByDesc('recorded_at')
            ->limit(200)
            ->get()
            ->filter(fn (TripPoint $p) => $p->accuracy_m === null || $p->accuracy_m <= $maxAccuracy)
            ->values();

        if ($recent->isEmpty()) {
            return null;
        }

        return $this->routeDeviation($ride, $recent->all(), $config) ?? $this->unusualStop($ride, $recent->all(), $config);
    }

    /**
     * @param  list<TripPoint>  $recent  newest first
     * @param  array<string, mixed>  $config
     */
    private function routeDeviation(Ride $ride, array $recent, array $config): ?Deviation
    {
        $needed = (int) $config['consecutive_samples'];

        if (count($recent) < $needed) {
            return null;
        }

        $plan = $this->plans->planFor($ride);
        $path = $plan?->plannedPoints() ?? [];

        if (count($path) < 2) {
            return null;
        }

        $distances = [];

        foreach (array_slice($recent, 0, $needed) as $point) {
            $off = PathDistance::metres($point->position(), $path);

            if ($off <= (float) $config['threshold_m'] + (float) ($point->accuracy_m ?? 0)) {
                return null;
            }

            $distances[] = $off;
        }

        $latest = $recent[0];

        return new Deviation(
            'route_deviation',
            $ride->id,
            (int) $ride->driver_id,
            $latest->position(),
            CarbonImmutable::now(),
            metres: (int) round($distances[0]),
            context: [
                'threshold_m' => (int) $config['threshold_m'],
                'samples' => $needed,
                'planned_provider' => $plan?->provider,
                'max_off_route_m' => (int) round(max($distances)),
            ],
        );
    }

    /**
     * @param  list<TripPoint>  $recent  newest first
     * @param  array<string, mixed>  $config
     */
    private function unusualStop(Ride $ride, array $recent, array $config): ?Deviation
    {
        $latest = $recent[0];
        $radius = (float) $config['stop_radius_m'];
        $oldestStationary = null;
        $samples = 0;

        foreach ($recent as $point) {
            if (Haversine::metres($latest->position(), $point->position()) > $radius) {
                break;
            }

            $oldestStationary = $point;
            $samples++;
        }

        if ($oldestStationary === null || $samples < 3) {
            return null;
        }

        $stoppedSeconds = (int) $oldestStationary->recorded_at->diffInSeconds($latest->recorded_at, true);

        if ($stoppedSeconds < (int) $config['stop_seconds']) {
            return null;
        }

        $toDropoff = null;

        if ($ride->dropoff_lat !== null && $ride->dropoff_lng !== null) {
            $toDropoff = Haversine::metres($latest->position(), LatLng::from($ride->dropoff_lat, $ride->dropoff_lng));

            if ($toDropoff <= (float) $config['dropoff_radius_m']) {
                return null;
            }
        }

        return new Deviation(
            'unusual_stop',
            $ride->id,
            (int) $ride->driver_id,
            $latest->position(),
            CarbonImmutable::now(),
            seconds: $stoppedSeconds,
            context: [
                'stop_radius_m' => (int) $radius,
                'samples' => $samples,
                'distance_to_dropoff_m' => $toDropoff !== null ? (int) round($toDropoff) : null,
            ],
        );
    }
}
