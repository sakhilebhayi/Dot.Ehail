<?php

namespace App\Domain\Dispatch\Contracts;

use App\Domain\Dispatch\Data\DriverPosition;

/**
 * Seam to the geo domain's LocationStore::nearbyDrivers / latest. The
 * default binding (Seams\AvailabilityDriverLocator) reads the latest position
 * the driver's heartbeat stored on driver_availability.
 */
interface DriverLocator
{
    /**
     * Online, fresh, not-on-trip drivers within $radiusM of the point, closest
     * first by straight line, at most $limit, never one of $excludeUserIds.
     *
     * @param  list<int>  $excludeUserIds
     * @return list<DriverPosition>
     */
    public function nearbyDrivers(float $lat, float $lng, int $radiusM, int $limit, array $excludeUserIds = []): array;

    public function latest(int $driverUserId): ?DriverPosition;
}
