<?php

namespace App\Domain\Safety\Seams;

use App\Models\Ride;

class NoRidePin implements RidePinSource
{
    public function forPassenger(Ride $ride): array
    {
        return ['required' => false, 'pin' => null];
    }
}
