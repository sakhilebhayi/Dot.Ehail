<?php

namespace App\Domain\Integration\Safety;

use App\Domain\Geo\Locations\LocationStore;
use App\Domain\Geo\Models\DriverLocation;
use App\Domain\Geo\Models\TripPoint;
use App\Domain\Safety\Seams\SafetyLocationSource;
use App\Models\Ride;

/**
 * Safety's location seam over geo's LocationStore (safety's default adapter named a class path that does not
 * exist, so it always reported "no position"). Latest fix of the ride's driver and the recorded trip trail.
 */
class GeoSafetyLocationSource implements SafetyLocationSource
{
    public function __construct(private readonly LocationStore $store) {}

    public function latestDriverPosition(Ride $ride): ?array
    {
        if ($ride->driver_id === null) {
            return null;
        }

        /** @var DriverLocation|null $row */
        $row = $this->store->latest([(int) $ride->driver_id])->get((int) $ride->driver_id);

        return $row === null ? null : ['lat' => $row->lat, 'lng' => $row->lng, 'recorded_at' => $row->recorded_at->toIso8601String()];
    }

    public function recentTrail(Ride $ride, int $limit = 20): array
    {
        return $this->store->trail($ride)
            ->take(-1 * $limit)
            ->map(fn (TripPoint $p) => ['lat' => (float) $p->lat, 'lng' => (float) $p->lng, 'recorded_at' => $p->recorded_at?->toIso8601String()])
            ->values()
            ->all();
    }

    public function available(): bool
    {
        return true;
    }
}
