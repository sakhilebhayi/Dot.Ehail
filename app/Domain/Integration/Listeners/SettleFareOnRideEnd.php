<?php

namespace App\Domain\Integration\Listeners;

use App\Domain\Payments\Jobs\ChargeCancellationFee;
use App\Domain\Pricing\Finalisation\CancellationFeePolicy;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Rides\Events\RideCancelled;
use App\Domain\Rides\Events\RideDomainEvent;
use App\Domain\Rides\Events\RideExpired;
use App\Domain\Rides\Events\RideFailed;
use App\Domain\Rides\Events\RideNoShow;
use App\Models\Ride;

/**
 * Closes the pricing record of a ride that ended without a trip, after the transition committed (D-703):
 *  - a no-show: the fee pricing settled in the transition is charged by money (money's own listeners react
 *    to cancellations only), as the passenger's;
 *  - a cancellation by the driver or an operator, an expiry or a failure: nothing is charged; the fare snapshot
 *    is settled at zero with the reason and the promotion is given back (a passenger cancellation was already
 *    settled inside its transition).
 * Both steps are idempotent (a settled snapshot is left alone; money de-duplicates fee charges).
 */
class SettleFareOnRideEnd
{
    public function __construct(private readonly CancellationFeePolicy $policy) {}

    public function handle(RideDomainEvent $event): void
    {
        if ($event instanceof RideNoShow) {
            ChargeCancellationFee::dispatch($event->rideId, 'passenger', $event->occurredAt->toIso8601String());

            return;
        }

        if (! ($event instanceof RideCancelled || $event instanceof RideExpired || $event instanceof RideFailed)) {
            return;
        }

        $fare = RideFare::query()->where('ride_id', $event->rideId)->first();
        $ride = Ride::find($event->rideId);

        if ($fare === null || $ride === null || $fare->isFinalised()) {
            return;
        }

        $this->policy->settle($ride, $this->policy->decide($ride, $event->actorType, $event->occurredAt));
    }
}
