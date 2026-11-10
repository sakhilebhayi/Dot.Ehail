<?php

namespace App\Domain\Dispatch\Seams;

use App\Domain\Dispatch\Contracts\CancellationFees;
use App\Enums\RideActor;
use App\Models\Ride;
use Carbon\CarbonInterface;

/**
 * Default CancellationFees: no fee policy exists until pricing is bound.
 */
class NoCancellationFees implements CancellationFees
{
    public function feeFor(Ride $ride, RideActor $actor, CarbonInterface $at, string $kind): ?int
    {
        return null;
    }
}
