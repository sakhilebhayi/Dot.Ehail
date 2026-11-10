<?php

namespace App\Domain\Rides\Events;

/**
 * The trip completed with a final fare.
 */
final class TripCompleted extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.trip_completed';
    }
}
