<?php

namespace App\Domain\Rides\Events;

/**
 * The assigned driver set off to the pickup point.
 */
final class DriverArriving extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.driver_arriving';
    }
}
