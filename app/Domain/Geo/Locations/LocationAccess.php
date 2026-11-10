<?php

namespace App\Domain\Geo\Locations;

use App\Models\Ride;
use App\Models\User;

/**
 * Who may see which position (POPIA: purpose limitation and minimality).
 *  - A passenger sees ONLY the driver assigned to their ride, ONLY while that ride is active.
 *  - A driver sees their own position; there is no passenger live position to see.
 *  - A platform operator may see a ride's driver position and trail (operations and safety).
 */
class LocationAccess
{
    public function canViewDriverOf(User $viewer, Ride $ride): bool
    {
        if ($ride->driver_id === null) {
            return false;
        }

        if ($viewer->isPlatformOperator()) {
            return true;
        }

        if ($viewer->id === $ride->driver_id) {
            return true;
        }

        return $viewer->id === $ride->passenger_id && RideTracking::isTracking($ride);
    }

    public function canViewTrail(User $viewer, Ride $ride): bool
    {
        return $viewer->isPlatformOperator();
    }
}
