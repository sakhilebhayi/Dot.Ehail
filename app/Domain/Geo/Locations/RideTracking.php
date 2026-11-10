<?php

namespace App\Domain\Geo\Locations;

use App\Models\Ride;

/**
 * Which ride states mean what for location data. Covers both the current status values and the R-46 lifecycle
 * names (docs/R46_ARCHITECTURE.md section 2) so this keeps working when dispatch lands the new states.
 */
final class RideTracking
{
    /**
     * A driver is assigned and the passenger may see that driver's live position.
     */
    public const TRACKING_STATUSES = [
        'accepted', 'en_route', 'arrived',
        'driver_assigned', 'driver_arriving', 'driver_arrived',
        'in_progress',
    ];

    /**
     * The trip itself: the trail is recorded and measured.
     */
    public const TRIP_STATUSES = ['in_progress'];

    public static function isTracking(Ride $ride): bool
    {
        return $ride->driver_id !== null && in_array($ride->status, self::TRACKING_STATUSES, true);
    }

    public static function isOnTrip(Ride $ride): bool
    {
        return $ride->driver_id !== null && in_array($ride->status, self::TRIP_STATUSES, true);
    }
}
