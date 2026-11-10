<?php

namespace App\Domain\Rides;

use App\Enums\RideActor;
use App\Enums\RideStatus;

/**
 * The edge table of the ride state machine: from -> to -> allowed actors ->
 * guards -> side effects. Nothing else in the application decides what may
 * follow what. RideStatus::allowedTransitions() reads this table and
 * RideStateMachine executes it (guards and effects are named here and
 * implemented there, so the table stays reviewable at a glance).
 *
 * Guards (all must pass; each is a no-op for actors it does not concern):
 *   party            the acting user is the ride's passenger (passenger actor) or
 *                    assigned driver (driver actor); operators and the system pass
 *   lead_time        a scheduled ride may only enter `requested` inside its allocation lead time
 *   unassigned       the ride has no driver yet
 *   driver_free      the incoming driver holds no other active ride
 *   pin              the trip PIN was verified (when the ride carries a PIN and the market requires it)
 *   no_show_wait     the driver has waited at pickup for the configured time
 *   can_requeue      the reassignment policy still allows another driver (driver actor only)
 *   requeue_spent    the reassignment policy is exhausted (driver actor only)
 *   final_fare       a fare exists to complete the ride with (driver actor only)
 *   not_started      the trip never started (needs_review -> searching)
 *   window_elapsed   the search window (ehail.ride_expiry_minutes) has run out (system expiry)
 *   slot_missed      a scheduled ride's pickup time plus grace has passed without dispatch
 *
 * Effects run in order inside the same transaction as the status change.
 */
final class RideTransitions
{
    /** @var array<string, array<string, array{actors: list<RideActor>, guards: list<string>, effects: list<string>}>>|null */
    private static ?array $table = null;

    /**
     * @return array<string, array{actors: list<RideActor>, guards: list<string>, effects: list<string>}>
     */
    public static function from(RideStatus $from): array
    {
        return self::table()[$from->value] ?? [];
    }

    /**
     * @return array{actors: list<RideActor>, guards: list<string>, effects: list<string>}|null
     */
    public static function edge(RideStatus $from, RideStatus $to): ?array
    {
        return self::table()[$from->value][$to->value] ?? null;
    }

    /**
     * @return array<string, array<string, array{actors: list<RideActor>, guards: list<string>, effects: list<string>}>>
     */
    public static function table(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }

        $p = RideActor::Passenger;
        $d = RideActor::Driver;
        $o = RideActor::Operator;
        $s = RideActor::System;

        $e = fn (array $actors, array $guards = [], array $effects = []) => [
            'actors' => $actors, 'guards' => $guards, 'effects' => $effects,
        ];

        $passengerCancel = $e([$p], ['party'], ['stamp_cancelled', 'withdraw_offers', 'cancellation_fee', 'release_driver']);
        $operatorCancel = $e([$o], [], ['stamp_cancelled', 'withdraw_offers', 'release_driver']);
        $systemFail = $e([$s], [], ['stamp_failed', 'withdraw_offers', 'release_driver']);
        $flag = $e([$o, $s], [], ['stamp_needs_review', 'withdraw_offers', 'release_driver']);
        // A driver who backs out (policy allows another driver), or an operator reassigning.
        $requeue = $e([$d, $o], ['party', 'can_requeue'], ['release_driver', 'requeue']);
        $driverCancel = $e([$d], ['party', 'requeue_spent'], ['stamp_cancelled', 'release_driver', 'unassign']);

        return self::$table = [
            RideStatus::Scheduled->value => [
                RideStatus::Requested->value => $e([$s], ['lead_time'], ['stamp_requested']),
                RideStatus::CancelledByPassenger->value => $passengerCancel,
                RideStatus::CancelledByOperator->value => $operatorCancel,
                RideStatus::Expired->value => $e([$s], ['slot_missed'], ['stamp_expired']),
                RideStatus::Failed->value => $systemFail,
            ],
            RideStatus::Requested->value => [
                RideStatus::Searching->value => $e([$s], [], ['start_search']),
                // A driver claiming from the open board before the first dispatch tick, or an operator assigning by hand.
                RideStatus::DriverAssigned->value => $e([$d, $o], ['unassigned', 'driver_free'], ['assign_driver', 'withdraw_offers']),
                RideStatus::CancelledByPassenger->value => $passengerCancel,
                RideStatus::CancelledByOperator->value => $operatorCancel,
                RideStatus::Expired->value => $e([$s], ['window_elapsed'], ['stamp_expired', 'withdraw_offers']),
                RideStatus::Failed->value => $systemFail,
            ],
            RideStatus::Searching->value => [
                RideStatus::DriverAssigned->value => $e([$d, $o], ['unassigned', 'driver_free'], ['assign_driver', 'withdraw_offers']),
                RideStatus::CancelledByPassenger->value => $passengerCancel,
                RideStatus::CancelledByOperator->value => $operatorCancel,
                RideStatus::Expired->value => $e([$s], ['window_elapsed'], ['stamp_expired', 'withdraw_offers']),
                RideStatus::Failed->value => $systemFail,
                RideStatus::NeedsReview->value => $flag,
            ],
            RideStatus::DriverAssigned->value => [
                RideStatus::DriverArriving->value => $e([$d], ['party'], ['stamp_arriving']),
                RideStatus::Searching->value => $requeue,
                RideStatus::CancelledByDriver->value => $driverCancel,
                RideStatus::CancelledByPassenger->value => $passengerCancel,
                RideStatus::CancelledByOperator->value => $operatorCancel,
                RideStatus::NeedsReview->value => $flag,
                RideStatus::Failed->value => $systemFail,
            ],
            RideStatus::DriverArriving->value => [
                RideStatus::DriverArrived->value => $e([$d], ['party'], ['stamp_arrived']),
                RideStatus::Searching->value => $requeue,
                RideStatus::CancelledByDriver->value => $driverCancel,
                RideStatus::CancelledByPassenger->value => $passengerCancel,
                RideStatus::CancelledByOperator->value => $operatorCancel,
                RideStatus::NeedsReview->value => $flag,
                RideStatus::Failed->value => $systemFail,
            ],
            RideStatus::DriverArrived->value => [
                RideStatus::InProgress->value => $e([$d], ['party', 'pin'], ['stamp_started']),
                RideStatus::NoShow->value => $e([$d], ['party', 'no_show_wait'], ['stamp_no_show', 'cancellation_fee', 'release_driver']),
                RideStatus::Searching->value => $requeue,
                RideStatus::CancelledByDriver->value => $driverCancel,
                RideStatus::CancelledByPassenger->value => $passengerCancel,
                RideStatus::CancelledByOperator->value => $operatorCancel,
                RideStatus::NeedsReview->value => $flag,
                RideStatus::Failed->value => $systemFail,
            ],
            RideStatus::InProgress->value => [
                RideStatus::Completed->value => $e([$d, $o], ['party', 'final_fare'], ['complete_trip', 'release_driver']),
                RideStatus::NeedsReview->value => $flag,
                RideStatus::CancelledByOperator->value => $operatorCancel,
            ],
            RideStatus::NeedsReview->value => [
                RideStatus::Completed->value => $e([$o], [], ['complete_trip', 'release_driver']),
                RideStatus::CancelledByOperator->value => $operatorCancel,
                RideStatus::Searching->value => $e([$o], ['not_started'], ['release_driver', 'requeue']),
            ],
            RideStatus::Completed->value => [],
            RideStatus::CancelledByPassenger->value => [],
            RideStatus::CancelledByDriver->value => [],
            RideStatus::CancelledByOperator->value => [],
            RideStatus::Expired->value => [],
            RideStatus::NoShow->value => [],
            RideStatus::Failed->value => [],
        ];
    }
}
