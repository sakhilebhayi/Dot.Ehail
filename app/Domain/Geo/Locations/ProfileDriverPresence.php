<?php

namespace App\Domain\Geo\Locations;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;

class ProfileDriverPresence implements DriverPresence
{
    public function isOnline(User $driver): bool
    {
        // DriverProfile carries a user global scope; bypass it so this works from any context (jobs, commands).
        return DriverProfile::withoutGlobalScope('user')
            ->where('user_id', $driver->id)
            ->where('status', 'approved')
            ->where('is_online', true)
            ->exists();
    }

    public function activeRideFor(User $driver): ?Ride
    {
        return Ride::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status', RideTracking::TRACKING_STATUSES)
            ->latest('id')
            ->first();
    }
}
