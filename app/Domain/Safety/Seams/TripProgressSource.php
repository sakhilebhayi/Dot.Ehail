<?php

namespace App\Domain\Safety\Seams;

use App\Models\Ride;

/**
 * Live progress for the public trip share page. Dispatch owns the ride feed (RideFeed); this seam
 * is what the safety domain reads. The default reads the ride's own status and the safety
 * location source; ETA is reported only when a source provides one.
 */
interface TripProgressSource
{
    /**
     * @return array{state: string, driver_position: array{lat: float, lng: float}|null, position_at: ?string, eta_seconds: ?int, eta_source: ?string}
     */
    public function progress(Ride $ride): array;
}
