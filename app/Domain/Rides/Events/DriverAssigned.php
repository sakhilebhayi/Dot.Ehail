<?php

namespace App\Domain\Rides\Events;

/**
 * A driver accepted the ride's offer, claimed it from the open board, or an operator assigned one.
 */
final class DriverAssigned extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.driver_assigned';
    }
}
