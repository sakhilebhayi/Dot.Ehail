<?php

namespace App\Domain\Integration\Geo;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Geo\Locations\DriverPresence;
use App\Domain\Geo\Locations\ProfileDriverPresence;
use App\Models\Ride;
use App\Models\User;

/**
 * Geo's DriverPresence from dispatch's availability (D-704): online means online, paused or on a trip in
 * driver_availability; the active ride is the one dispatch holds for the driver (rides.active_driver_id).
 * Pre-R-46 drivers with no availability row fall back to geo's profile-based default.
 */
class AvailabilityDriverPresence implements DriverPresence
{
    public function __construct(private readonly ProfileDriverPresence $legacy) {}

    public function isOnline(User $driver): bool
    {
        $state = DriverAvailability::query()->where('user_id', $driver->id)->value('state');

        if ($state === null) {
            return $this->legacy->isOnline($driver);
        }

        return $state !== DriverAvailability::OFFLINE;
    }

    public function activeRideFor(User $driver): ?Ride
    {
        return Ride::query()->where('active_driver_id', $driver->id)->first()
            ?? $this->legacy->activeRideFor($driver);
    }
}
