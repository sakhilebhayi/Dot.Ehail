<?php

namespace App\Domain\Integration\Dispatch;

use App\Domain\Dispatch\Contracts\DriverLocator;
use App\Domain\Dispatch\Data\DriverPosition;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Locations\LocationStore;
use App\Domain\Geo\Locations\NearbyDriver;
use App\Domain\Geo\Models\DriverLocation;
use Illuminate\Support\Carbon;

/**
 * Dispatch's DriverLocator over geo's LocationStore (D-704): positions are geo's screened latest fixes
 * (accuracy, jumps, clock skew filtered); availability (online, not on a trip, fresh heartbeat) is
 * dispatch's. A driver is a candidate only when both agree.
 */
class GeoDriverLocator implements DriverLocator
{
    public function __construct(private readonly LocationStore $store) {}

    public function nearbyDrivers(float $lat, float $lng, int $radiusM, int $limit, array $excludeUserIds = []): array
    {
        $stale = (int) config('ehail_dispatch.heartbeat_stale_seconds');
        $nearby = $this->store->nearbyDrivers(new LatLng($lat, $lng), $radiusM, ['exclude' => $excludeUserIds, 'max_age_seconds' => $stale]);

        if ($nearby === []) {
            return [];
        }

        $available = DriverAvailability::query()
            ->whereIn('user_id', array_map(fn (NearbyDriver $d) => $d->driverId, $nearby))
            ->where('state', DriverAvailability::ONLINE)
            ->whereNull('current_ride_id')
            ->where('last_heartbeat_at', '>=', now()->subSeconds($stale))
            ->get(['user_id', 'vehicle_id', 'location_version'])
            ->keyBy('user_id');

        $positions = [];

        foreach ($nearby as $driver) {
            $row = $available->get($driver->driverId);

            if ($row !== null) {
                $positions[] = $this->position($driver->location, $row->vehicle_id, (int) round($driver->distanceM), (int) $row->location_version);
            }
        }

        usort($positions, fn (DriverPosition $a, DriverPosition $b) => $a->straightLineM <=> $b->straightLineM ?: $a->userId <=> $b->userId);

        return array_slice($positions, 0, $limit);
    }

    public function latest(int $driverUserId): ?DriverPosition
    {
        $location = DriverLocation::query()->where('driver_id', $driverUserId)->first();

        if ($location === null) {
            return null;
        }

        $availability = DriverAvailability::query()->where('user_id', $driverUserId)->first(['vehicle_id', 'location_version']);

        return $this->position($location, $availability?->vehicle_id, null, (int) ($availability?->location_version ?? 0));
    }

    private function position(DriverLocation $location, ?int $vehicleId, ?int $distanceM, int $version): DriverPosition
    {
        return new DriverPosition(
            $location->driver_id,
            $location->lat,
            $location->lng,
            $location->heading_deg === null ? null : ((int) round($location->heading_deg)) % 360,
            Carbon::instance($location->recorded_at),
            $vehicleId,
            $distanceM,
            $version,
        );
    }
}
