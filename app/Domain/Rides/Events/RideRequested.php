<?php

namespace App\Domain\Rides\Events;

/**
 * A ride entered requested (on demand, or a scheduled ride reaching its allocation lead time).
 */
final class RideRequested extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.requested';
    }
}
