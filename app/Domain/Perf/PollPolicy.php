<?php

namespace App\Domain\Perf;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Enums\RideStatus;

/**
 * Adaptive polling (docs/R46_PERF.md section 2, D-1502): the server tells each client how long to wait before
 * its next poll (Retry-After and poll_after_s), from what can happen next:
 *  - driver with an offer on screen: 2 s (an expiry or withdrawal shows within 2 s; accept is a tap, not a poll);
 *  - driver on a trip: 3 s; online and waiting: 3 s (an offer is seen on average 1.5 s after it is made, inside its
 *    15 s window); battery or data saver while waiting: 6 s; paused: 15 s (no offer can arrive); offline: 30 s;
 *  - passenger searching or with a driver on the way: 3 s; in the car: 5 s (nothing to decide, the map still moves
 *    with every position); scheduled or ended: 30 s. A hidden tab is slowed by the client itself (15 s) and polls at
 *    once when it becomes visible again.
 * Every action (accept, arrive, start, cancel) answers with the new state, so nobody waits for a poll after acting.
 */
class PollPolicy
{
    public function forDriverState(?string $state, bool $hasOffer, bool $hasRide, bool $saver = false): int
    {
        $c = config('ehail_perf.driver_poll');

        return match (true) {
            $hasOffer => (int) $c['offer'],
            $hasRide || $state === DriverAvailability::ON_TRIP => (int) $c['trip'],
            $state === DriverAvailability::ONLINE => (int) ($saver ? $c['saver'] : $c['idle']),
            $state === DriverAvailability::PAUSED => (int) $c['paused'],
            default => (int) $c['offline'],
        };
    }

    public function forPassenger(RideStatus $status): int
    {
        $c = config('ehail_perf.passenger_poll');

        return match (true) {
            $status === RideStatus::InProgress => (int) $c['in_progress'],
            $status->holdsDriver() => (int) $c['approaching'],
            $status === RideStatus::Requested || $status === RideStatus::Searching => (int) $c['searching'],
            default => (int) $c['scheduled'],
        };
    }
}
