<?php

namespace App\Domain\Integration\Dispatch;

use App\Domain\Dispatch\Contracts\CancellationFees;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Pricing\Finalisation\CancellationFeePolicy;
use App\Enums\RideActor;
use App\Models\Ride;
use Carbon\CarbonInterface;

/**
 * Dispatch's CancellationFees over pricing's CancellationFeePolicy (D-703). Called by the state machine
 * inside the cancellation / no-show transaction: pricing decides (free before assignment, free window,
 * driver running late, no-show after the free wait) and settles the ride's fare snapshot in the same
 * transaction; the money domain charges what was settled once the transaction commits.
 *
 * A no-show is recorded by the driver but the fee is the passenger's, so it is decided as the passenger's.
 * "Running late" is dispatch knowledge: the driver has not arrived and is past the pickup ETA promised in
 * the accepted offer plus a grace period (config ehail_integration.driver_late_grace_seconds).
 */
class PricingCancellationFees implements CancellationFees
{
    public function __construct(private readonly CancellationFeePolicy $policy) {}

    public function feeFor(Ride $ride, RideActor $actor, CarbonInterface $at, string $kind): ?int
    {
        $chargedActor = $kind === 'no_show' ? RideActor::Passenger : $actor;
        $decision = $this->policy->decide($ride, $chargedActor, $at, null, null, $this->driverRunningLate($ride, $at));

        $this->policy->settle($ride, $decision);

        return $decision->charges() ? $decision->fee->amountMinor : null;
    }

    public function driverRunningLate(Ride $ride, CarbonInterface $at): bool
    {
        if ($ride->driver_id === null || $ride->accepted_at === null || $ride->arrived_at !== null) {
            return false;
        }

        $eta = RideOffer::query()->where('ride_id', $ride->id)->where('driver_id', $ride->driver_id)
            ->where('status', RideOffer::ACCEPTED)->latest('id')->value('pickup_eta_s');

        if ($eta === null) {
            return false;
        }

        $promised = $ride->accepted_at->copy()->addSeconds((int) $eta + (int) config('ehail_integration.driver_late_grace_seconds', 300));

        return $promised->lessThan($at);
    }
}
