<?php

namespace App\Domain\Integration\Money;

use App\Domain\Payments\Seams\CancellationFeeSource;
use App\Domain\Pricing\Models\RideFare;
use App\Models\Ride;
use Carbon\CarbonInterface;

/**
 * The fee money charges is the one pricing decided and settled on the ride's fare snapshot in the cancellation
 * (or no-show) transaction (D-703): basis cancellation_fee with a positive total. Nothing settled, no fee.
 * The actor and time are already part of pricing's decision; they are not re-evaluated here.
 */
class PricingCancellationFeeSource implements CancellationFeeSource
{
    public function feeFor(Ride $ride, string $actor, CarbonInterface $at): ?int
    {
        $fare = RideFare::query()->where('ride_id', $ride->id)->where('basis', 'cancellation_fee')->first();

        return $fare !== null && $fare->final_total_minor > 0 ? (int) $fare->final_total_minor : null;
    }
}
