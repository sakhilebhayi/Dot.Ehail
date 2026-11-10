<?php

namespace App\Domain\Safety\Seams;

use App\Models\Ride;
use Throwable;

/**
 * Default TripProgressSource. State comes from the ride row; position from SafetyLocationSource
 * (rounded by the caller); ETA from dispatch's RideFeed when it is bound and its payload carries
 * one (eta_seconds or eta_s), otherwise null. Nothing is estimated here.
 */
class RideRecordProgressSource implements TripProgressSource
{
    public const FEED = 'App\\Domain\\Dispatch\\RideFeed';

    public function __construct(private readonly SafetyLocationSource $locations) {}

    public function progress(Ride $ride): array
    {
        $position = $this->locations->latestDriverPosition($ride);
        $eta = null;

        if ((interface_exists(self::FEED) && app()->bound(self::FEED)) || class_exists(self::FEED)) {
            try {
                $feed = app(self::FEED)->forPassenger($ride, 0);
                $feed = is_array($feed) ? $feed : (method_exists($feed, 'toArray') ? $feed->toArray() : (array) $feed);
                $value = $feed['eta_seconds'] ?? $feed['eta_s'] ?? ($feed['ride']['eta_seconds'] ?? null);
                $eta = is_numeric($value) ? (int) $value : null;
            } catch (Throwable) {
                $eta = null;
            }
        }

        return [
            'state' => (string) $ride->status,
            'driver_position' => $position ? ['lat' => $position['lat'], 'lng' => $position['lng']] : null,
            'position_at' => $position['recorded_at'] ?? null,
            'eta_seconds' => $eta,
            'eta_source' => $eta !== null ? 'dispatch' : null,
        ];
    }
}
