<?php

namespace App\Domain\Dispatch\Seams;

use App\Domain\Dispatch\Contracts\DriverLocator;
use App\Domain\Dispatch\Data\DriverPosition;
use App\Domain\Dispatch\Geo;
use App\Domain\Dispatch\Models\DriverAvailability;

/**
 * Default DriverLocator: the latest heartbeat position on driver_availability.
 * A bounding-box range scan on the (state, last_lat, last_lng) index, then an
 * exact great-circle filter in PHP. Rebind to the geo domain's LocationStore
 * once it is merged if that store becomes the single latest-position source.
 */
class AvailabilityDriverLocator implements DriverLocator
{
    public function nearbyDrivers(float $lat, float $lng, int $radiusM, int $limit, array $excludeUserIds = []): array
    {
        [$minLat, $maxLat, $minLng, $maxLng] = Geo::boundingBox($lat, $lng, $radiusM);
        $fresh = now()->subSeconds((int) config('ehail_dispatch.heartbeat_stale_seconds'));

        $rows = DriverAvailability::query()
            ->where('state', DriverAvailability::ONLINE)
            ->whereBetween('last_lat', [$minLat, $maxLat])
            ->whereBetween('last_lng', [$minLng, $maxLng])
            ->whereNull('current_ride_id')
            ->where('last_heartbeat_at', '>=', $fresh)
            ->where('last_location_at', '>=', $fresh)
            ->when($excludeUserIds !== [], fn ($q) => $q->whereNotIn('user_id', $excludeUserIds))
            ->get(['user_id', 'vehicle_id', 'last_lat', 'last_lng', 'heading', 'last_location_at']);

        $positions = [];

        foreach ($rows as $row) {
            $distance = Geo::distanceM($lat, $lng, $row->last_lat, $row->last_lng);

            if ($distance <= $radiusM) {
                $positions[] = new DriverPosition($row->user_id, $row->last_lat, $row->last_lng, $row->heading, $row->last_location_at, $row->vehicle_id, $distance);
            }
        }

        usort($positions, fn (DriverPosition $a, DriverPosition $b) => $a->straightLineM <=> $b->straightLineM ?: $a->userId <=> $b->userId);

        return array_slice($positions, 0, $limit);
    }

    public function latest(int $driverUserId): ?DriverPosition
    {
        $row = DriverAvailability::where('user_id', $driverUserId)->first(['user_id', 'vehicle_id', 'last_lat', 'last_lng', 'heading', 'last_location_at']);

        if ($row === null || $row->last_lat === null || $row->last_location_at === null) {
            return null;
        }

        return new DriverPosition($row->user_id, $row->last_lat, $row->last_lng, $row->heading, $row->last_location_at, $row->vehicle_id);
    }
}
