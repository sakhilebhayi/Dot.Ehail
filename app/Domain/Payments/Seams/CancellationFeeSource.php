<?php

namespace App\Domain\Payments\Seams;

use App\Models\Ride;
use Carbon\CarbonInterface;

/**
 * The contract's CancellationFeePolicy::feeFor(Ride, actor, at): ?Money, in minor units of the
 * platform currency. Null = no fee.
 */
interface CancellationFeeSource
{
    public function feeFor(Ride $ride, string $actor, CarbonInterface $at): ?int;
}
