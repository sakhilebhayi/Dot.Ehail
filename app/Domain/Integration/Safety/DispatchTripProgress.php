<?php

namespace App\Domain\Integration\Safety;

use App\Domain\Dispatch\RideFeed;
use App\Domain\Safety\Seams\SafetyLocationSource;
use App\Domain\Safety\Seams\TripProgressSource;
use App\Models\Ride;

/**
 * Trip progress for the public trip-share page and incident context: state from the ride, the driver's
 * position from geo, and the ETA dispatch shows the passenger (to pickup, or to drop-off during the trip)
 * with its method ("road" or "straight_line_estimate"), never invented.
 */
class DispatchTripProgress implements TripProgressSource
{
    public function __construct(private readonly RideFeed $feed, private readonly SafetyLocationSource $locations) {}

    public function progress(Ride $ride): array
    {
        $position = $this->locations->latestDriverPosition($ride);
        $driver = $this->feed->forPassenger($ride, 0)['body']['driver_position'] ?? null;
        $eta = is_array($driver) && is_int($driver['eta_s'] ?? null) ? $driver['eta_s'] : null;

        return [
            'state' => (string) $ride->status,
            'driver_position' => $position ? ['lat' => $position['lat'], 'lng' => $position['lng']] : null,
            'position_at' => $position['recorded_at'] ?? null,
            'eta_seconds' => $eta,
            'eta_source' => $eta !== null ? 'dispatch:'.($driver['eta_method'] ?? 'unknown') : null,
        ];
    }
}
