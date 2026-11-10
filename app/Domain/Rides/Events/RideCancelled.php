<?php

namespace App\Domain\Rides\Events;

/**
 * The ride ended as cancelled_by_passenger, cancelled_by_driver or cancelled_by_operator (toStatus says which).
 */
final class RideCancelled extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.cancelled';
    }
}
