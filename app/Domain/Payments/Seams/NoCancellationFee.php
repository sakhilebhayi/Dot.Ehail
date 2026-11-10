<?php

namespace App\Domain\Payments\Seams;

use App\Models\Ride;
use Carbon\CarbonInterface;

/**
 * Bound until the pricing domain supplies its CancellationFeePolicy: no cancellation fee is ever
 * charged. Inventing a fee here would charge passengers under a policy nobody set.
 */
class NoCancellationFee implements CancellationFeeSource
{
    public function feeFor(Ride $ride, string $actor, CarbonInterface $at): ?int
    {
        return null;
    }
}
