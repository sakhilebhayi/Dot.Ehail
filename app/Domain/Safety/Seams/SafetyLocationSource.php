<?php

namespace App\Domain\Safety\Seams;

use App\Models\Ride;

/**
 * Where the safety domain gets driver positions from. The geo domain owns location storage
 * (LocationStore in docs/R46_ARCHITECTURE.md section 3); GeoLocationStoreAdapter forwards to it
 * when it is bound and reports "not available" otherwise. Never invents a position.
 */
interface SafetyLocationSource
{
    /** @return array{lat: float, lng: float, recorded_at: ?string}|null */
    public function latestDriverPosition(Ride $ride): ?array;

    /** @return list<array{lat: float, lng: float, recorded_at: ?string}> */
    public function recentTrail(Ride $ride, int $limit = 20): array;
}
