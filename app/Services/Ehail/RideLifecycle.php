<?php

namespace App\Services\Ehail;

use App\Domain\Dispatch\Dispatcher;
use App\Domain\Rides\RideStateMachine;
use App\Domain\Rides\TransitionOptions;
use App\Enums\CancelReason;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;

/**
 * The pre-R-46 entry point the existing pages and operator desk call. Since
 * R-46 it holds no rules of its own: every state change goes through
 * App\Domain\Rides\RideStateMachine (edge table, row lock, guards, timeline,
 * domain events) and dispatch decisions through App\Domain\Dispatch\Dispatcher.
 *
 * Controllers authorise (policies) and translate InvalidRideTransition into a
 * validation error.
 */
class RideLifecycle
{
    public function __construct(
        private readonly RideStateMachine $machine,
        private readonly Dispatcher $dispatcher,
    ) {}

    /**
     * A driver claims an open request from the open board (the pre-R-46
     * "available rides" queue, now the trip-radar fallback to sequential offers).
     */
    public function accept(Ride $ride, User $driver): Ride
    {
        return $this->dispatcher->claimFromBoard($ride, $driver);
    }

    /**
     * The assigned driver moves the ride one stage forward.
     */
    public function advance(Ride $ride, User $driver, RideStatus $to, ?string $pin = null): Ride
    {
        return $this->dispatcher->advance($ride, $driver, $to, $pin);
    }

    /**
     * A passenger cancels (the ride ends), or the assigned driver backs out
     * (the ride goes back to searching with priority, or ends as
     * cancelled_by_driver once it has been handed back max_reassignments times).
     */
    public function cancel(Ride $ride, User $actor, CancelReason $reason, ?string $note = null): Ride
    {
        return match ($ride->actorFor($actor)) {
            RideActor::Passenger => $this->machine->transition($ride, RideStatus::CancelledByPassenger, RideActor::Passenger, $actor,
                new TransitionOptions(reason: $reason->value, note: $note)),
            RideActor::Driver => $this->dispatcher->driverCancel($ride, $actor, $reason, $note),
            default => throw new InvalidRideTransition('Only the passenger or the assigned driver can cancel this ride.'),
        };
    }

    public function operatorComplete(Ride $ride, User $operator, float $finalFare, string $reason): Ride
    {
        return $this->machine->transition($ride, RideStatus::Completed, RideActor::Operator, $operator,
            new TransitionOptions(reason: 'operator_entered_fare', note: $reason, finalFareDecimal: $finalFare));
    }

    public function operatorCancel(Ride $ride, User $operator, string $reason): Ride
    {
        return $this->machine->transition($ride, RideStatus::CancelledByOperator, RideActor::Operator, $operator,
            new TransitionOptions(reason: CancelReason::Other->value, note: $reason));
    }

    /**
     * Expire every request that has searched past the window. Safe to run any
     * number of times: each ride is re-read under a lock and the window guard
     * is checked again there.
     */
    public function expireStaleRequests(): int
    {
        $cutoff = now()->subMinutes((int) config('ehail.ride_expiry_minutes'));
        $count = 0;

        $ids = Ride::whereIn('status', [RideStatus::Requested->value, RideStatus::Searching->value])
            ->whereRaw('coalesce(search_started_at, requested_at, created_at) < ?', [$cutoff])
            ->pluck('id');

        foreach ($ids as $id) {
            $ride = Ride::find($id);

            if ($ride === null) {
                continue;
            }

            try {
                $this->machine->transition($ride, RideStatus::Expired, RideActor::System, null,
                    new TransitionOptions(key: 'expire:'.$ride->reassignment_count, reason: 'no_driver_found'));
                $count++;
            } catch (InvalidRideTransition) {
                // Accepted, cancelled or re-queued between the query and the lock.
            }
        }

        return $count;
    }

    /**
     * Flag in-progress trips nobody has finished. They are never completed
     * automatically -- an operator decides what the fare and outcome were.
     */
    public function flagStuckTrips(): int
    {
        $cutoff = now()->subHours((int) config('ehail.stuck_ride_hours'));
        $count = 0;

        $ids = Ride::where('status', RideStatus::InProgress->value)
            ->where(function ($q) use ($cutoff) {
                $q->where('started_at', '<', $cutoff)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('started_at')->where('updated_at', '<', $cutoff));
            })
            ->pluck('id');

        foreach ($ids as $id) {
            $ride = Ride::find($id);

            if ($ride === null || $ride->statusEnum() !== RideStatus::InProgress || ($ride->started_at ?? $ride->updated_at)->gte($cutoff)) {
                continue;
            }

            try {
                $this->machine->transition($ride, RideStatus::NeedsReview, RideActor::System, null,
                    new TransitionOptions(key: 'stuck:'.($ride->started_at?->timestamp ?? 0), reason: 'trip_not_finished'));
                $count++;
            } catch (InvalidRideTransition) {
                // Completed or cancelled between the query and the lock.
            }
        }

        return $count;
    }
}
