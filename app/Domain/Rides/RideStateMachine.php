<?php

namespace App\Domain\Rides;

use App\Domain\Dispatch\AvailabilityService;
use App\Domain\Dispatch\Contracts\CancellationFees;
use App\Domain\Dispatch\Contracts\CategoryEligibility;
use App\Domain\Dispatch\Contracts\TripFareFinaliser;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Rides\Events\DriverArrived;
use App\Domain\Rides\Events\DriverArriving;
use App\Domain\Rides\Events\DriverAssigned;
use App\Domain\Rides\Events\RideCancelled;
use App\Domain\Rides\Events\RideDomainEvent;
use App\Domain\Rides\Events\RideEventBroadcast;
use App\Domain\Rides\Events\RideExpired;
use App\Domain\Rides\Events\RideFailed;
use App\Domain\Rides\Events\RideNeedsReview;
use App\Domain\Rides\Events\RideNoShow;
use App\Domain\Rides\Events\RideRequested;
use App\Domain\Rides\Events\RideRequeued;
use App\Domain\Rides\Events\TripCompleted;
use App\Domain\Rides\Events\TripStarted;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use App\Services\Ehail\InvalidRideTransition;
use App\Services\Ehail\RidePayments;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only code that changes rides.status. One method, transition(), runs
 * the edge from RideTransitions for the acting role:
 *
 *   1. one DB transaction, the ride row locked (lockForUpdate) and re-read;
 *   2. idempotency: a transition key already on the ride's timeline is a
 *      replay and returns the ride unchanged;
 *   3. the edge must exist and allow the actor; every guard must pass
 *      (InvalidRideTransition otherwise, with a message for the person);
 *   4. effects compute the attribute changes, the row is saved (the
 *      nullable-unique guard columns make a second active ride for the same
 *      driver or passenger fail at the database even if a guard was raced);
 *   5. post-save effects (driver availability, offers), the timeline row,
 *      the pre-R-46 notices, and the domain event (after commit).
 */
class RideStateMachine
{
    public function __construct(
        private readonly RideTimeline $timeline,
        private readonly AvailabilityService $availability,
        private readonly RideNotices $notices,
        private readonly TripFareFinaliser $finaliser,
        private readonly CancellationFees $fees,
        private readonly CategoryEligibility $categories,
        private readonly TripPin $pin,
    ) {}

    public function transition(Ride $ride, RideStatus $to, RideActor $actor, ?User $user = null, ?TransitionOptions $options = null): Ride
    {
        $options ??= new TransitionOptions;

        try {
            return DB::transaction(fn () => $this->apply($ride, $to, $actor, $user, $options), 3);
        } catch (UniqueConstraintViolationException $e) {
            throw new InvalidRideTransition($this->uniqueMessage($e, $actor));
        }
    }

    private function apply(Ride $ride, RideStatus $to, RideActor $actor, ?User $user, TransitionOptions $options): Ride
    {
        $locked = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();
        $from = $locked->statusEnum();

        if ($options->key !== null && $this->timeline->hasKey($locked, $options->key)) {
            return $locked;
        }

        $edge = RideTransitions::edge($from, $to);

        if ($edge === null || ! in_array($actor, $edge['actors'], true)) {
            if ($to === RideStatus::DriverAssigned && ! $from->isAwaitingDriver()) {
                throw new InvalidRideTransition('That ride was just claimed by another driver.');
            }

            throw InvalidRideTransition::between($from, $to);
        }

        foreach ($edge['guards'] as $guard) {
            $this->guard($guard, $locked, $actor, $user, $options);
        }

        $previousDriverId = $locked->driver_id;
        $changes = ['status' => $to->value];

        foreach ($edge['effects'] as $effect) {
            $changes = array_merge($changes, $this->attributesFor($effect, $locked, $from, $to, $actor, $user, $options));
        }

        $changes = array_merge($changes, $options->attributes);

        if ($to === RideStatus::Completed) {
            $changes['payment_status'] = app(RidePayments::class)
                ->statusOnCompletion($changes['final_fare'] ?? $locked->final_fare)->value;
        }

        $locked->forceFill($changes)->save();

        foreach ($edge['effects'] as $effect) {
            $this->afterSave($effect, $locked, $previousDriverId);
        }

        $this->timeline->append(
            $locked, 'transition', $actor, $user?->id, $options->reason,
            array_filter(array_merge($options->context, ['note' => $options->note]), fn ($v) => $v !== null),
            $from->value, $to->value,
            $options->key ?? sprintf('%s>%s#%d', $from->value, $to->value, $locked->version),
        );

        $this->notices->afterTransition($locked, $from, $to, $actor, $user, $options);
        $this->dispatchDomainEvent($locked, $from, $to, $actor, $user, $options);

        return $locked;
    }

    private function guard(string $guard, Ride $ride, RideActor $actor, ?User $user, TransitionOptions $options): void
    {
        $fail = fn (string $message) => throw new InvalidRideTransition($message);

        match ($guard) {
            'party' => match ($actor) {
                RideActor::Passenger => $user?->id === $ride->passenger_id ?: $fail('That ride is not yours.'),
                RideActor::Driver => ($ride->driver_id !== null && $user?->id === $ride->driver_id) ?: $fail('That ride is not assigned to you.'),
                default => true,
            },
            'lead_time' => ($options->force || $ride->scheduled_for === null
                || $ride->scheduled_for->copy()->subMinutes((int) config('ehail_dispatch.scheduled.allocation_lead_minutes'))->lte(now()))
                ?: $fail('This scheduled ride is not due for dispatch yet.'),
            'unassigned' => $ride->driver_id === null ?: $fail('That ride was just claimed by another driver.'),
            'driver_free' => $this->driverIsFree($this->incomingDriver($actor, $user, $options), $ride)
                ?: $fail($actor === RideActor::Driver ? 'You already have an active ride.' : 'That driver already has an active ride.'),
            'pin' => (! $this->pin->required($ride) || $options->pinVerified)
                ?: $fail('Enter the passenger\'s 4-digit PIN to start the trip.'),
            'no_show_wait' => ($ride->arrived_at !== null
                && $ride->arrived_at->copy()->addMinutes((int) config('ehail_dispatch.no_show_wait_minutes'))->lte(now()))
                ?: $fail('You can record a no-show once you have waited '.(int) config('ehail_dispatch.no_show_wait_minutes').' minutes at the pickup point.'),
            'can_requeue' => ($actor !== RideActor::Driver || $ride->reassignment_count < (int) config('ehail.max_reassignments'))
                ?: $fail('This ride cannot go back to the queue again.'),
            'requeue_spent' => ($actor !== RideActor::Driver || $ride->reassignment_count >= (int) config('ehail.max_reassignments'))
                ?: $fail('This ride goes back to the queue when you leave it.'),
            'final_fare' => ($actor !== RideActor::Driver || $this->finaliser->finalise($ride) !== null)
                ?: $fail('No fare estimate exists for this ride, so it cannot be completed here. A platform operator has to enter the final fare.'),
            'not_started' => $ride->started_at === null ?: $fail('A trip that started cannot be dispatched again.'),
            'window_elapsed' => ($ride->search_started_at ?? $ride->requested_at ?? $ride->created_at)
                ->lt(now()->subMinutes((int) config('ehail.ride_expiry_minutes')))
                ?: $fail('This ride is still inside its search window.'),
            'slot_missed' => ($ride->scheduled_for !== null
                && $ride->scheduled_for->copy()->addMinutes((int) config('ehail_dispatch.scheduled.grace_minutes'))->lt(now()))
                ?: $fail('This scheduled ride\'s pickup time has not passed.'),
            default => throw new \LogicException("Unknown ride guard {$guard}."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(string $effect, Ride $ride, RideStatus $from, RideStatus $to, RideActor $actor, ?User $user, TransitionOptions $options): array
    {
        $now = now();

        return match ($effect) {
            'stamp_requested' => ['requested_at' => $now],
            'start_search' => [
                'search_started_at' => $now, 'search_round' => 0, 'next_dispatch_at' => $now,
                'requested_at' => $ride->requested_at ?? $now,
            ],
            'assign_driver' => $this->assignment($ride, $actor, $user, $options),
            'withdraw_offers' => ['pending_offer_expires_at' => null, 'next_dispatch_at' => null],
            'stamp_arriving' => ['driver_arriving_at' => $now],
            'stamp_arrived' => ['arrived_at' => $now],
            'stamp_started' => ['started_at' => $now],
            'complete_trip' => $this->completion($ride, $actor, $user, $options),
            'release_driver' => [],
            // cancelled_by records who; the ride no longer shows a driver.
            'unassign' => ['driver_id' => null, 'vehicle_id' => null, 'accepted_at' => null],
            'requeue' => [
                'driver_id' => null, 'vehicle_id' => null, 'accepted_at' => null, 'started_at' => null,
                'driver_arriving_at' => null, 'arrived_at' => null,
                // A fresh search window; the original request time stays in created_at.
                'requested_at' => $now, 'search_started_at' => $now, 'search_round' => 0,
                'next_dispatch_at' => $now, 'pending_offer_expires_at' => null,
                'dispatch_priority' => $ride->dispatch_priority + 1,
                'reassignment_count' => $ride->reassignment_count + 1,
            ],
            'stamp_cancelled' => [
                'cancelled_at' => $now,
                'cancelled_by' => $user?->id,
                'cancel_reason' => $options->reason,
                'cancel_note' => $options->note,
            ],
            'cancellation_fee' => ['cancellation_fee_minor' => $this->fees->feeFor(
                $ride, $actor, $now, $to === RideStatus::NoShow ? 'no_show' : 'passenger_cancel',
            )],
            'stamp_expired' => ['expired_at' => $now],
            'stamp_no_show' => ['no_show_at' => $now],
            'stamp_failed' => ['failed_at' => $now, 'failure_reason' => $options->reason],
            'stamp_needs_review' => ['needs_review_at' => $now, 'needs_review_reason' => $options->note ?? $options->reason],
            default => throw new \LogicException("Unknown ride effect {$effect}."),
        };
    }

    private function afterSave(string $effect, Ride $ride, ?int $previousDriverId): void
    {
        match ($effect) {
            'assign_driver' => $this->availability->markOnTrip($ride->driver_id, $ride),
            'release_driver' => $previousDriverId !== null ? $this->availability->releaseFromRide($previousDriverId, $ride) : null,
            'withdraw_offers' => RideOffer::where('ride_id', $ride->id)->where('status', RideOffer::PENDING)->update([
                'status' => RideOffer::WITHDRAWN, 'pending_ride_id' => null, 'pending_driver_id' => null,
                'responded_at' => now(), 'reason' => 'ride_'.$ride->status, 'updated_at' => now(), 'version' => FeedVersion::now(),
            ]),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function assignment(Ride $ride, RideActor $actor, ?User $user, TransitionOptions $options): array
    {
        $driver = $this->incomingDriver($actor, $user, $options)
            ?? throw new InvalidRideTransition('No driver was named for this assignment.');

        $vehicle = $options->vehicle ?? DriverProfile::withoutGlobalScope('user')
            ->where('user_id', $driver->id)->first()?->activeVehicle();
        $category = $ride->category_code ?? $ride->vehicle_type;

        if ($vehicle === null || ! $vehicle->is_active || ! $this->categories->vehicleCanServe($vehicle, $category)) {
            throw new InvalidRideTransition('Your active vehicle cannot serve this ride\'s vehicle type.');
        }

        return ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'accepted_at' => now()];
    }

    /**
     * @return array<string, mixed>
     */
    private function completion(Ride $ride, RideActor $actor, ?User $user, TransitionOptions $options): array
    {
        if ($actor === RideActor::Operator && ($options->finalFareMinor !== null || $options->finalFareDecimal !== null)) {
            $minor = $options->finalFareMinor ?? (int) round($options->finalFareDecimal * 100);

            return [
                'completed_at' => now(),
                'final_fare' => $options->finalFareDecimal ?? $minor / 100,
                'final_fare_minor' => $minor,
                'final_fare_reason' => $options->note,
                'final_fare_set_by' => $user?->id,
            ];
        }

        $fare = $this->finaliser->finalise($ride)
            ?? throw new InvalidRideTransition('No fare exists for this ride, so it cannot be completed here. A platform operator has to enter the final fare.');

        return [
            'completed_at' => now(),
            'final_fare_minor' => $fare->totalMinor,
            'final_fare' => $fare->totalMinor / 100,
            'currency' => $ride->currency ?? $fare->currency,
        ];
    }

    private function incomingDriver(RideActor $actor, ?User $user, TransitionOptions $options): ?User
    {
        return $actor === RideActor::Driver ? $user : $options->driver;
    }

    private function driverIsFree(?User $driver, Ride $ride): bool
    {
        if ($driver === null) {
            return false;
        }

        return ! Ride::where('active_driver_id', $driver->id)->whereKeyNot($ride->id)->exists();
    }

    private function uniqueMessage(UniqueConstraintViolationException $e, RideActor $actor): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'active_driver') || str_contains($message, 'current_ride') => $actor === RideActor::Driver
                ? 'You already have an active ride.' : 'That driver already has an active ride.',
            str_contains($message, 'active_passenger') => 'This passenger already has a ride in progress.',
            str_contains($message, 'transition_key') => 'That change was already made.',
            default => 'That change clashed with another one. Refresh and try again.',
        };
    }

    private function dispatchDomainEvent(Ride $ride, RideStatus $from, RideStatus $to, RideActor $actor, ?User $user, TransitionOptions $options): void
    {
        $class = match (true) {
            $to === RideStatus::Requested => RideRequested::class,
            $to === RideStatus::Searching && ! $from->isAwaitingDriver() => RideRequeued::class,
            $to === RideStatus::DriverAssigned => DriverAssigned::class,
            $to === RideStatus::DriverArriving => DriverArriving::class,
            $to === RideStatus::DriverArrived => DriverArrived::class,
            $to === RideStatus::InProgress => TripStarted::class,
            $to === RideStatus::Completed => TripCompleted::class,
            $to->isCancelled() => RideCancelled::class,
            $to === RideStatus::Expired => RideExpired::class,
            $to === RideStatus::NoShow => RideNoShow::class,
            $to === RideStatus::Failed => RideFailed::class,
            $to === RideStatus::NeedsReview => RideNeedsReview::class,
            default => null,
        };

        if ($class === null) {
            return;
        }

        $this->emit(new $class($ride->id, $user?->id, $actor->value, now(), $options->reason, $from->value, $to->value));
    }

    public function emit(RideDomainEvent $event): void
    {
        event($event);

        if (config('ehail_dispatch.broadcast_events')) {
            broadcast(new RideEventBroadcast($event));
        }
    }
}
