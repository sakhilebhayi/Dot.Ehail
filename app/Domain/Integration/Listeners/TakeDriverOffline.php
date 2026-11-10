<?php

namespace App\Domain\Integration\Listeners;

use App\Domain\Dispatch\AvailabilityService;
use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Verification\Events\DriverForcedOffline;
use App\Models\User;

/**
 * Verification took a driver offline (expired or rejected mandatory document): dispatch stops offering them
 * rides at once and withdraws a pending offer. A driver on a trip finishes it and is then released offline,
 * not online (AvailabilityService::releaseFromRide reads the profile), D-714.
 */
class TakeDriverOffline
{
    public function __construct(private readonly AvailabilityService $availability) {}

    public function handle(DriverForcedOffline $event): void
    {
        $driver = User::find($event->driverUserId);
        $row = $driver === null ? null : $this->availability->forDriver($driver);

        if ($row === null || $row->state === DriverAvailability::OFFLINE || $row->state === DriverAvailability::ON_TRIP) {
            return;
        }

        try {
            $this->availability->goOffline($driver);
        } catch (DispatchRefused) {
            // On a trip after all (raced): released offline when it ends.
        }
    }
}
