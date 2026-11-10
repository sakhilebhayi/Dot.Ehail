<?php

namespace App\Domain\Rides\Events;

/**
 * The assigned driver is at the pickup point (the no-show wait starts).
 */
final class DriverArrived extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.driver_arrived';
    }
}
