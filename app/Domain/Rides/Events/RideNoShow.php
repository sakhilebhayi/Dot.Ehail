<?php

namespace App\Domain\Rides\Events;

/**
 * The driver waited the policy time at pickup and recorded a no-show.
 */
final class RideNoShow extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.no_show';
    }
}
