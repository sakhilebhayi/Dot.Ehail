<?php

namespace Tests\Concerns;

use App\Domain\Geo\Exceptions\RoutingUnavailable;
use App\Domain\Geo\Geometry\Haversine;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Models\TripPoint;
use App\Domain\Geo\Routing\RouteOptions;
use App\Domain\Geo\Routing\RouteResult;
use App\Domain\Geo\Routing\RoutingDriver;
use Carbon\CarbonImmutable;

/**
 * Synthetic GPS trails for the geo tests: deterministic pseudo-noise, no randomness between runs.
 */
trait BuildsGeoTrails
{
    /**
     * A point $metres east and $northMetres north of $origin.
     */
    protected function offset(LatLng $origin, float $eastMetres, float $northMetres = 0): LatLng
    {
        return new LatLng(
            $origin->lat + rad2deg($northMetres / Haversine::EARTH_RADIUS_M),
            $origin->lng + rad2deg($eastMetres / (Haversine::EARTH_RADIUS_M * cos(deg2rad($origin->lat)))),
        );
    }

    /**
     * Unsaved trip points driving east at $speed m/s, one every $interval seconds, with +-$noise m of
     * deterministic jitter.
     *
     * @return list<TripPoint>
     */
    protected function eastboundTrail(LatLng $origin, int $count, float $speed = 10, int $interval = 4, float $noise = 0, float $accuracy = 8, ?CarbonImmutable $start = null): array
    {
        $start ??= CarbonImmutable::parse('2026-11-10 08:00:00');
        $points = [];

        for ($i = 0; $i < $count; $i++) {
            $jitterEast = $noise * sin($i * 12.9898);
            $jitterNorth = $noise * cos($i * 78.233);
            $points[] = $this->tripPoint($this->offset($origin, $i * $speed * $interval + $jitterEast, $jitterNorth), $start->addSeconds($i * $interval), $accuracy);
        }

        return $points;
    }

    protected function tripPoint(LatLng $at, CarbonImmutable $when, ?float $accuracy = 8, int $rideId = 0, int $driverId = 0): TripPoint
    {
        return new TripPoint([
            'ride_id' => $rideId,
            'driver_id' => $driverId,
            'lat' => $at->lat,
            'lng' => $at->lng,
            'accuracy_m' => $accuracy,
            'recorded_at' => $when,
            'received_at' => $when,
        ]);
    }

    /**
     * A routing driver that answers with a fixed distance (or fails), counting calls.
     */
    protected function stubRoutingDriver(?int $distanceM = 1000, string $failWith = ''): RoutingDriver
    {
        return new class($distanceM, $failWith) implements RoutingDriver
        {
            public int $calls = 0;

            public function __construct(private ?int $distanceM, private string $failWith) {}

            public function name(): string
            {
                return 'stub';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function route(LatLng $from, LatLng $to, RouteOptions $options): RouteResult
            {
                $this->calls++;

                if ($this->failWith !== '') {
                    throw new RoutingUnavailable($this->failWith, 'stub');
                }

                return new RouteResult((int) $this->distanceM, 120, '', 'stub');
            }
        };
    }
}
