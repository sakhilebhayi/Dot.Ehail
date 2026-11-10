<?php

namespace App\Domain\Geo\Distance;

use App\Domain\Geo\Exceptions\RoutingUnavailable;
use App\Domain\Geo\Geometry\Haversine;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Locations\LocationStore;
use App\Domain\Geo\Models\RideRoute;
use App\Domain\Geo\Models\TripPoint;
use App\Domain\Geo\Routing\RoutePlanner;
use App\Models\Ride;
use Illuminate\Support\Collection;

/**
 * Turns a recorded trip trail into a travelled and a billable distance. Filters inaccurate fixes, speed outliers
 * (GPS jumps) and stationary jitter; bridges signal gaps (tunnels, dead zones) with a road route when a routing
 * provider is configured, and says so in the result when it could not.
 */
class DistanceMeter
{
    public function __construct(private LocationStore $locations, private RoutePlanner $routes) {}

    public function actual(Ride $ride): TripDistance
    {
        $summary = RideRoute::query()->where('ride_id', $ride->id)->first();

        if ($summary?->trail_purged_at !== null && $summary->travelled_m !== null) {
            return new TripDistance(
                (int) $summary->travelled_m,
                (int) $summary->billable_m,
                'stored_summary',
                (string) ($summary->distance_quality ?? 'good'),
            );
        }

        return $this->measure($this->locations->trail($ride));
    }

    /**
     * Straight-line pickup to drop-off: informational only, never billed.
     */
    public function straightLine(Ride $ride): ?int
    {
        if ($ride->pickup_lat === null || $ride->pickup_lng === null || $ride->dropoff_lat === null || $ride->dropoff_lng === null) {
            return null;
        }

        return (int) round(Haversine::metres(
            LatLng::from($ride->pickup_lat, $ride->pickup_lng),
            LatLng::from($ride->dropoff_lat, $ride->dropoff_lng),
        ));
    }

    /**
     * @param  Collection<int, TripPoint>|list<TripPoint>  $points  oldest first
     * @param  bool  $rerouteGaps  false skips routing calls (retention job); gaps then stay straight lines
     */
    public function measure(Collection|array $points, bool $rerouteGaps = true): TripDistance
    {
        $config = config('ehail-geo.distance');
        $maxSpeed = (float) config('ehail-geo.locations.max_speed_mps', 70);
        $flags = [];
        $dropped = 0;

        $kept = [];

        foreach ($points as $point) {
            if ($point->accuracy_m !== null && $point->accuracy_m > (float) $config['max_point_accuracy_m']) {
                $dropped++;
                $flags['inaccurate_points'] = true;

                continue;
            }

            $kept[] = $point;
        }

        if (count($kept) < 2) {
            return new TripDistance(0, 0, 'no_data', 'insufficient', array_keys($flags), count($kept), $dropped);
        }

        $travelled = 0.0;
        $billable = 0.0;
        $rerouted = 0;
        $unresolved = 0;
        $used = 1;
        $anchor = $kept[0];

        for ($i = 1, $n = count($kept); $i < $n; $i++) {
            $point = $kept[$i];
            $seconds = max(0.001, $anchor->recorded_at->diffInMilliseconds($point->recorded_at, true) / 1000);
            $metres = Haversine::metres($anchor->position(), $point->position());

            if ($metres / $seconds > $maxSpeed) {
                $dropped++;
                $flags['speed_outliers'] = true;

                continue;
            }

            if ($seconds > (float) $config['gap_seconds'] && $metres > (float) $config['gap_distance_m']) {
                $travelled += $metres;

                try {
                    if (! $rerouteGaps) {
                        throw RoutingUnavailable::notConfigured();
                    }

                    $route = $this->routes->route($anchor->position(), $point->position());
                    // A road is never shorter than the straight line between its ends.
                    $billable += max($metres, (float) $route->distanceM);
                    $rerouted++;
                } catch (RoutingUnavailable) {
                    $billable += $metres;
                    $unresolved++;
                }

                $anchor = $point;
                $used++;

                continue;
            }

            // Two fixes of a parked car differ by up to about the sum of their accuracy radii; only movement beyond
            // that counts. Progress is measured from the anchor, so slow traffic still accumulates.
            $jitter = max((float) $config['jitter_floor_m'], ($anchor->accuracy_m ?? 10) + ($point->accuracy_m ?? 10));
            $deviceSaysParked = $point->speed_mps !== null && $point->speed_mps < 0.5 && $anchor->speed_mps !== null && $anchor->speed_mps < 0.5;

            if ($metres < $jitter || ($deviceSaysParked && $metres < 2 * $jitter)) {
                continue;
            }

            $travelled += $metres;
            $billable += $metres;
            $anchor = $point;
            $used++;
        }

        if ($unresolved > 0) {
            $flags['unresolved_gap'] = true;
        }

        $method = match (true) {
            $unresolved > 0 => 'gps_with_straight_gaps',
            $rerouted > 0 => 'gps_with_route_gaps',
            default => 'gps',
        };

        $dropShare = $dropped / max(1, count($points));
        $quality = ($unresolved > 0 || $dropShare > 0.3) ? 'degraded' : 'good';

        return new TripDistance(
            (int) round($travelled),
            (int) round($billable),
            $method,
            $quality,
            array_keys($flags),
            $used,
            $dropped,
            $rerouted,
        );
    }
}
