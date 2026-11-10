<?php

namespace App\Domain\Rides\Events;

/**
 * No driver accepted within the search window (or a scheduled ride missed its slot).
 */
final class RideExpired extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.expired';
    }
}
