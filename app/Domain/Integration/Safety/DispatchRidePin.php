<?php

namespace App\Domain\Integration\Safety;

use App\Domain\Rides\TripPin;
use App\Domain\Safety\Seams\RidePinSource;
use App\Enums\RideStatus;
use App\Models\Ride;

/**
 * The ride PIN for the passenger's safety centre: the same rule as the passenger feed (shown from driver
 * assignment until the trip starts, only when the market requires a PIN).
 */
class DispatchRidePin implements RidePinSource
{
    public function __construct(private readonly TripPin $pins) {}

    public function forPassenger(Ride $ride): array
    {
        $required = $this->pins->required($ride);
        $visible = $required && in_array($ride->statusEnum(), [RideStatus::DriverAssigned, RideStatus::DriverArriving, RideStatus::DriverArrived], true);

        return ['required' => $required, 'pin' => $visible ? $ride->pin_encrypted : null];
    }
}
