<?php

namespace App\Domain\Geo\Locations;

use App\Models\Ride;
use App\Models\User;

/**
 * Seam to the dispatch domain: whether a driver may report a position and which ride they are on. The default
 * reads driver_profiles.is_online and rides; dispatch rebinds this to its AvailabilityService when it lands.
 */
interface DriverPresence
{
    public function isOnline(User $driver): bool;

    public function activeRideFor(User $driver): ?Ride;
}
