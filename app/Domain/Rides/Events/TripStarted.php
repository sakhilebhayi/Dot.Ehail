<?php

namespace App\Domain\Rides\Events;

/**
 * The trip started (PIN verified where required).
 */
final class TripStarted extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.trip_started';
    }
}
