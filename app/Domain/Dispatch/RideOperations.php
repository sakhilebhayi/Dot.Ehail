<?php

namespace App\Domain\Dispatch;

use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Rides\RideStateMachine;
use App\Domain\Rides\TransitionOptions;
use App\Enums\CancelReason;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Operations API backend (the wave-2 ops command centre calls it). Every
 * action goes through the state machine with the operator as actor and a
 * written reason, so each one lands on the ride's timeline and in the audit
 * log (RideNotices records ride.operator_cancelled, ride.operator_reassigned,
 * ride.operator_assigned, ride.flagged_for_review, ride.fare_entered_by_operator).
 */
class RideOperations
{
    public function __construct(
        private readonly RideStateMachine $machine,
        private readonly Dispatcher $dispatcher,
    ) {}

    /**
     * Live rides: everything not yet in a terminal state, oldest first, with
     * what an operator needs to triage. Optional filter by status.
     */
    public function live(?string $status = null, int $perPage = 50): LengthAwarePaginator
    {
        $live = array_values(array_filter(RideStatus::cases(), fn (RideStatus $s) => ! $s->isTerminal()));

        return Ride::query()
            ->whereIn('status', $status !== null ? [$status] : RideStatus::values($live))
            ->with(['passenger:id,name', 'driver:id,name'])
            ->orderByDesc('dispatch_priority')
            ->orderBy('created_at')
            ->paginate($perPage);
    }

    public function forceCancel(Ride $ride, User $operator, string $reason): Ride
    {
        return $this->machine->transition($ride, RideStatus::CancelledByOperator, RideActor::Operator, $operator,
            new TransitionOptions(reason: CancelReason::Other->value, note: $reason));
    }

    /**
     * Back to searching (with priority), or straight to a named driver.
     */
    public function reassign(Ride $ride, User $operator, string $reason, ?User $driver = null): Ride
    {
        return DB::transaction(function () use ($ride, $operator, $reason, $driver) {
            $status = $ride->fresh()->statusEnum();

            if ($status->holdsDriver() || $status === RideStatus::NeedsReview) {
                $ride = $this->machine->transition($ride, RideStatus::Searching, RideActor::Operator, $operator,
                    new TransitionOptions(reason: 'operator_reassign', note: $reason));
            }

            if ($driver === null) {
                return $ride;
            }

            if ($driver->id === $ride->passenger_id) {
                throw new DispatchRefused('driver_is_passenger', 'A passenger cannot be assigned as their own driver.');
            }

            return $this->machine->transition($ride, RideStatus::DriverAssigned, RideActor::Operator, $operator,
                new TransitionOptions(reason: 'operator_assign', note: $reason, driver: $driver));
        }, 3);
    }

    public function markNeedsReview(Ride $ride, User $operator, string $reason): Ride
    {
        return $this->machine->transition($ride, RideStatus::NeedsReview, RideActor::Operator, $operator,
            new TransitionOptions(reason: 'operator_flagged', note: $reason));
    }

    /**
     * @param  'complete'|'cancel'|'redispatch'  $outcome
     */
    public function resolve(Ride $ride, User $operator, string $outcome, string $reason, ?int $finalFareMinor = null): Ride
    {
        if ($ride->statusEnum() !== RideStatus::NeedsReview) {
            throw new DispatchRefused('not_in_review', 'Only a ride that needs review can be resolved.');
        }

        return match ($outcome) {
            'complete' => $this->machine->transition($ride, RideStatus::Completed, RideActor::Operator, $operator,
                new TransitionOptions(reason: 'operator_entered_fare', note: $reason,
                    finalFareMinor: $finalFareMinor ?? throw new DispatchRefused('fare_required', 'Enter the final fare to complete the ride.'))),
            'cancel' => $this->forceCancel($ride, $operator, $reason),
            'redispatch' => tap($this->machine->transition($ride, RideStatus::Searching, RideActor::Operator, $operator,
                new TransitionOptions(reason: 'operator_redispatch', note: $reason)), fn (Ride $r) => $this->dispatcher->tickRide($r)),
            default => throw new DispatchRefused('unknown_outcome', 'Choose complete, cancel or redispatch.'),
        };
    }
}
