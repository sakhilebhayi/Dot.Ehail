<?php

namespace App\Services\Ehail;

use App\Enums\CancelReason;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\RideNoticeNotification;
use App\Services\Notify\EhailNotifyEvents;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Every ride state change goes through here. Each public method runs in one
 * transaction on a row it has locked and re-read, checks the edge against
 * RideStatus::allowedTransitions() for the acting role, writes, and records
 * what happened. RideObserver then broadcasts the new status; RideStatusUpdated
 * waits for the commit, so a rolled-back change is never announced.
 *
 * Controllers authorise (policies) and translate InvalidRideTransition into a
 * validation error; they no longer decide what may follow what.
 */
class RideLifecycle
{
    public function accept(Ride $ride, User $driver): Ride
    {
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();

        $claimed = $this->transition(
            $ride, RideStatus::Accepted, RideActor::Driver,
            function (Ride $locked) use ($driver, $profile) {
                $vehicle = $profile->activeVehicleFor($locked->vehicle_type);

                if ($vehicle === null) {
                    throw new InvalidRideTransition('Your active vehicle cannot serve this ride\'s vehicle type.');
                }

                return ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'accepted_at' => now()];
            },
            // Queued for Dot.Notify only once this transaction commits.
            after: fn (Ride $locked) => app(EhailNotifyEvents::class)->rideAccepted($locked->load(['passenger', 'driver'])),
            guard: fn (Ride $locked) => $locked->driver_id === null,
        );

        return $claimed ?? throw new InvalidRideTransition('That ride was just claimed by another driver.');
    }

    /**
     * The assigned driver moves the ride one stage forward.
     */
    public function advance(Ride $ride, User $driver, RideStatus $to): Ride
    {
        return $this->transition(
            $ride, $to, RideActor::Driver,
            function (Ride $locked) use ($to) {
                if ($locked->statusEnum()->nextStage() !== $to) {
                    throw InvalidRideTransition::between($locked->statusEnum(), $to);
                }

                if ($to === RideStatus::InProgress) {
                    return ['started_at' => now()];
                }

                if ($to === RideStatus::Completed) {
                    // No meter or GPS trace exists, so the estimate made at
                    // request time is the only fare this app can produce. No
                    // estimate means an operator has to enter the fare.
                    if ($locked->estimated_fare === null) {
                        throw new InvalidRideTransition('No fare estimate exists for this ride, so it cannot be completed here. A platform operator has to enter the final fare.');
                    }

                    return ['completed_at' => now(), 'final_fare' => $locked->estimated_fare];
                }

                return [];
            },
            guard: fn (Ride $locked) => $locked->driver_id === $driver->id,
        ) ?? throw new InvalidRideTransition('That ride is not assigned to you.');
    }

    /**
     * A passenger cancels (the ride ends), or the assigned driver backs out
     * (the ride goes back to the queue, or expires once it has been handed
     * back max_reassignments times). Whoever is on the other side is told.
     */
    public function cancel(Ride $ride, User $actor, CancelReason $reason, ?string $note = null): Ride
    {
        $role = $ride->actorFor($actor);

        return match ($role) {
            RideActor::Passenger => $this->cancelByPassenger($ride, $actor, $reason, $note),
            RideActor::Driver => $this->driverBacksOut($ride, $actor, $reason, $note),
            default => throw new InvalidRideTransition('Only the passenger or the assigned driver can cancel this ride.'),
        };
    }

    public function operatorComplete(Ride $ride, User $operator, float $finalFare, string $reason): Ride
    {
        return $this->transition(
            $ride, RideStatus::Completed, RideActor::Operator,
            fn (Ride $locked) => [
                'completed_at' => now(),
                'final_fare' => $finalFare,
                'final_fare_reason' => $reason,
                'final_fare_set_by' => $operator->id,
            ],
            after: function (Ride $locked, RideStatus $from) use ($operator, $reason) {
                AuditLog::record('ride.fare_entered_by_operator', $operator, $locked, [
                    'previous_status' => $from->value,
                    'estimated_fare' => $locked->estimated_fare,
                    'final_fare' => $locked->final_fare,
                    'reason' => $reason,
                ]);
            },
        );
    }

    public function operatorCancel(Ride $ride, User $operator, string $reason): Ride
    {
        return $this->transition(
            $ride, RideStatus::Cancelled, RideActor::Operator,
            fn (Ride $locked) => [
                'cancelled_by' => $operator->id,
                'cancel_reason' => CancelReason::Other->value,
                'cancel_note' => $reason,
                'cancelled_at' => now(),
            ],
            after: function (Ride $locked, RideStatus $from) use ($operator, $reason) {
                AuditLog::record('ride.operator_cancelled', $operator, $locked, [
                    'previous_status' => $from->value,
                    'reason' => $reason,
                ]);

                foreach (array_filter([$locked->passenger, $locked->driver]) as $party) {
                    $this->notify($party, $locked, 'ride_cancelled', 'Ride cancelled by an operator',
                        "Ride #{$locked->id} was cancelled by a platform operator after review.",
                        'operator', 'Cancelled by a platform operator after review');
                }
            },
        );
    }

    /**
     * Expire every request that has waited past the window. Safe to run any
     * number of times: each ride is re-read under a lock and only expires if
     * it is still a stale request.
     */
    public function expireStaleRequests(): int
    {
        $cutoff = now()->subMinutes((int) config('ehail.ride_expiry_minutes'));
        $count = 0;

        $ids = Ride::where('status', RideStatus::Requested->value)
            ->where('requested_at', '<', $cutoff)
            ->pluck('id');

        foreach ($ids as $id) {
            $ride = Ride::find($id);

            if ($ride === null) {
                continue;
            }

            try {
                $expired = $this->transition(
                    $ride, RideStatus::Expired, RideActor::System,
                    fn (Ride $locked) => ['expired_at' => now()],
                    after: function (Ride $locked) {
                        $minutes = (int) config('ehail.ride_expiry_minutes');
                        $this->notify($locked->passenger, $locked, 'ride_expired', 'No driver found',
                            "Ride #{$locked->id} expired: no driver accepted within {$minutes} minutes. You can request again.");
                    },
                    guard: fn (Ride $locked) => $locked->requested_at !== null && $locked->requested_at->lt($cutoff),
                );
            } catch (InvalidRideTransition) {
                // Accepted or cancelled between the query and the lock.
                continue;
            }

            $count += $expired ? 1 : 0;
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

            if ($ride === null) {
                continue;
            }

            try {
                $flagged = $this->transition(
                    $ride, RideStatus::NeedsReview, RideActor::System,
                    fn (Ride $locked) => ['needs_review_at' => now()],
                    guard: fn (Ride $locked) => ($locked->started_at ?? $locked->updated_at)->lt($cutoff),
                );
            } catch (InvalidRideTransition) {
                // Completed or cancelled between the query and the lock.
                continue;
            }

            $count += $flagged ? 1 : 0;
        }

        return $count;
    }

    private function cancelByPassenger(Ride $ride, User $passenger, CancelReason $reason, ?string $note): Ride
    {
        return $this->transition(
            $ride, RideStatus::Cancelled, RideActor::Passenger,
            fn (Ride $locked) => [
                'cancelled_by' => $passenger->id,
                'cancel_reason' => $reason->value,
                'cancel_note' => $note,
                'cancelled_at' => now(),
            ],
            after: function (Ride $locked) use ($reason) {
                if ($locked->driver) {
                    $this->notify($locked->driver, $locked, 'ride_cancelled', 'Passenger cancelled',
                        "Ride #{$locked->id} was cancelled by the passenger.",
                        'passenger', $reason->label());
                }
            },
            guard: fn (Ride $locked) => $locked->passenger_id === $passenger->id,
        ) ?? throw new InvalidRideTransition('That ride is not yours to cancel.');
    }

    private function driverBacksOut(Ride $ride, User $driver, CancelReason $reason, ?string $note): Ride
    {
        $outcome = null;

        $result = DB::transaction(function () use ($ride, $driver, $reason, $note, &$outcome) {
            $locked = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();
            $exhausted = $locked->reassignment_count >= (int) config('ehail.max_reassignments');
            $outcome = $exhausted ? RideStatus::Expired : RideStatus::Requested;

            return $this->transition(
                $locked, $outcome, RideActor::Driver,
                fn (Ride $r) => $exhausted
                    ? ['expired_at' => now(), 'driver_id' => null, 'vehicle_id' => null, 'accepted_at' => null]
                    : [
                        'driver_id' => null,
                        'vehicle_id' => null,
                        'accepted_at' => null,
                        'started_at' => null,
                        // A fresh waiting window; the original request time
                        // stays in created_at.
                        'requested_at' => now(),
                        'reassignment_count' => $r->reassignment_count + 1,
                    ],
                after: function (Ride $r, RideStatus $from) use ($driver, $reason, $note, $exhausted) {
                    AuditLog::record('ride.driver_cancelled', $driver, $r, [
                        'reason' => $reason->value,
                        'note' => $note,
                        'previous_status' => $from->value,
                        'reassignment_count' => $r->reassignment_count,
                        'outcome' => $exhausted ? 'expired' : 'requeued',
                    ]);

                    $exhausted
                        ? $this->notify($r->passenger, $r, 'ride_expired', 'No driver found',
                            "Ride #{$r->id} ended: your drivers kept cancelling and no other driver was assigned. You can request again.")
                        : $this->notify($r->passenger, $r, 'ride_requeued', 'Your driver cancelled',
                            "Your driver for ride #{$r->id} cancelled. We are looking for another driver.",
                            'driver', $reason->label());
                },
                guard: fn (Ride $r) => $r->driver_id === $driver->id,
            );
        });

        return $result ?? throw new InvalidRideTransition('That ride is not assigned to you.');
    }

    /**
     * Lock the row, re-read it, check the edge for this actor, apply the
     * attributes, run the follow-up. Returns null if $guard says the ride is
     * no longer in the state the caller saw (a lost race, or a stale-ness
     * check that no longer holds); callers decide whether that is an error.
     *
     * @param  Closure(Ride): array<string, mixed>  $attributes
     * @param  Closure(Ride, RideStatus): void|null  $after
     * @param  Closure(Ride): bool|null  $guard
     */
    private function transition(Ride $ride, RideStatus $to, RideActor $actor, Closure $attributes, ?Closure $after = null, ?Closure $guard = null): ?Ride
    {
        return DB::transaction(function () use ($ride, $to, $actor, $attributes, $after, $guard) {
            $locked = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();
            $from = $locked->statusEnum();

            if (! $from->canTransitionTo($to, $actor)) {
                // A guard failing on a ride that has already moved on is the
                // normal "someone got there first" outcome, not an invalid ask.
                if ($guard !== null && ! $guard($locked)) {
                    return null;
                }

                throw InvalidRideTransition::between($from, $to);
            }

            if ($guard !== null && ! $guard($locked)) {
                return null;
            }

            $changes = array_merge($attributes($locked), ['status' => $to->value]);

            if ($to === RideStatus::Completed) {
                // Completing is the moment a fare becomes due: unpaid when Paystack is usable and the
                // fare is above zero, otherwise not_due, exactly as before online payment existed.
                $changes['payment_status'] = app(RidePayments::class)
                    ->statusOnCompletion($changes['final_fare'] ?? $locked->final_fare)->value;
            }

            // payment_status is not mass-assignable, so it is written explicitly.
            $locked->forceFill($changes)->save();

            if ($after !== null) {
                $after($locked, $from);
            }

            return $locked;
        });
    }

    private function notify(?User $user, Ride $ride, string $type, string $title, string $message, ?string $cancelledByRole = null, ?string $reason = null): void
    {
        $user?->notify(new RideNoticeNotification($ride, $type, $title, $message, $cancelledByRole, $reason));
    }
}
