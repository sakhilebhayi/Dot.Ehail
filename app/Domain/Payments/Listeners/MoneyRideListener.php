<?php

namespace App\Domain\Payments\Listeners;

use App\Domain\Payments\Jobs\ChargeCancellationFee;
use App\Domain\Payments\Jobs\SettleCompletedRide;
use App\Domain\Payments\Seams\CancellationFeeSource;
use App\Domain\Payments\Seams\NoCancellationFee;
use App\Domain\Rides\Events\RideCancelled;
use App\Domain\Rides\Events\TripCompleted;
use App\Models\Ride;

/**
 * Money reacts to the ride lifecycle. Two inputs, one outcome (both idempotent):
 *  - the contract's domain events (TripCompleted, RideCancelled) from the dispatch domain;
 *  - the ride row itself reaching "completed" / "cancelled", so rides completed by any code path
 *    (the existing lifecycle, an operator) are settled even before dispatch emits the events.
 */
class MoneyRideListener
{
    public function tripCompleted(TripCompleted $event): void
    {
        SettleCompletedRide::dispatch($event->rideId);
    }

    public function rideCancelled(RideCancelled $event): void
    {
        if (app(CancellationFeeSource::class) instanceof NoCancellationFee) {
            return;
        }

        ChargeCancellationFee::dispatch($event->rideId, $event->actorType, $event->occurredAt->toIso8601String());
    }

    public function rideUpdated(Ride $ride): void
    {
        if (! $ride->wasChanged('status')) {
            return;
        }

        if ($ride->status === 'completed') {
            SettleCompletedRide::dispatch($ride->id);

            return;
        }

        // No fee policy bound (the default): nothing to charge, so no job is queued.
        if (str_starts_with((string) $ride->status, 'cancelled') && ! app(CancellationFeeSource::class) instanceof NoCancellationFee) {
            $actor = match (true) {
                $ride->cancelled_by !== null && $ride->cancelled_by === $ride->passenger_id => 'passenger',
                $ride->cancelled_by !== null && $ride->cancelled_by === $ride->driver_id => 'driver',
                $ride->status === 'cancelled_by_passenger' => 'passenger',
                $ride->status === 'cancelled_by_driver' => 'driver',
                default => 'operator',
            };

            ChargeCancellationFee::dispatch($ride->id, $actor, now()->toIso8601String());
        }
    }
}
