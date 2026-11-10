<?php

namespace App\Domain\Rides\Events;

/**
 * A driver backed out (or an operator reassigned) and the ride went back to searching with priority.
 */
final class RideRequeued extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.requeued';
    }
}
