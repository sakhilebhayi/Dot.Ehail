<?php

namespace App\Domain\Integration\Dispatch;

use App\Domain\Dispatch\Contracts\CancellationFees;
use App\Domain\Dispatch\Contracts\DriverLocator;
use App\Domain\Dispatch\Geo;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Pricing\Finalisation\CancellationDecision;
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
    public function __construct(
        private readonly CancellationFeePolicy $policy,
        private readonly DriverLocator $locator,
    ) {}

    public function feeFor(Ride $ride, RideActor $actor, CarbonInterface $at, string $kind): ?int
    {
        $chargedActor = $kind === 'no_show' ? RideActor::Passenger : $actor;
        $decision = $this->policy->decide($ride, $chargedActor, $at, null, null, $this->driverRunningLate($ride, $at));

        // A no-show fee needs the driver's phone at the pickup when it is declared: "arrived" alone can be tapped
        // from anywhere (R-46 security S-17). Rides without pickup coordinates have nothing to measure against.
        if ($kind === 'no_show' && $decision->charges() && ! $this->driverAtPickup($ride)) {
            $decision = new CancellationDecision(null, 'no_show_unverified', 'No no-show fee: the driver\'s phone did not place them at the pickup.');
        }

        $this->policy->settle($ride, $decision);

        return $decision->charges() ? $decision->fee->amountMinor : null;
    }

    public function driverAtPickup(Ride $ride): bool
    {
        if ($ride->pickup_lat === null || $ride->pickup_lng === null || $ride->driver_id === null) {
            return true;
        }

        $position = $this->locator->latest((int) $ride->driver_id);

        if ($position === null || $position->recordedAt->diffInSeconds(now(), true) > (int) config('ehail_driver.arrival.max_fix_age_s', 120)) {
            return false;
        }

        $tolerance = (int) config('ehail_driver.arrival.tolerance_m', 150) + (int) config('ehail_driver.arrival.accuracy_allowance_cap_m', 100);

        return Geo::distanceM($position->lat, $position->lng, (float) $ride->pickup_lat, (float) $ride->pickup_lng) <= $tolerance;
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
