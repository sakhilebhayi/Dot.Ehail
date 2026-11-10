<?php

namespace App\Domain\Safety\Seams;

use App\Models\Ride;

/**
 * Hook for the wave-2 passenger safety centre: dispatch owns the 4-digit ride PIN (contract
 * section 2). Until dispatch binds its implementation, NoRidePin says "no PIN on this ride".
 */
interface RidePinSource
{
    /** @return array{required: bool, pin: ?string} pin only for the passenger of an active ride */
    public function forPassenger(Ride $ride): array;
}
