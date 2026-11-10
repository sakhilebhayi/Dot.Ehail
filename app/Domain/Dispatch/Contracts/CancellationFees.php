<?php

namespace App\Domain\Dispatch\Contracts;

use App\Enums\RideActor;
use App\Models\Ride;
use Carbon\CarbonInterface;

/**
 * Seam to the pricing domain's CancellationFeePolicy::feeFor(Ride, actor, at).
 * Minor units in the ride's currency, or null for no fee. The default binding
 * charges nothing (no fee policy exists before pricing is merged).
 */
interface CancellationFees
{
    /**
     * @param  string  $kind  passenger_cancel | no_show
     */
    public function feeFor(Ride $ride, RideActor $actor, CarbonInterface $at, string $kind): ?int;
}
