<?php

namespace App\Domain\Rides\Events;

/**
 * A system fault ended the ride (reasonCode says which).
 */
final class RideFailed extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.failed';
    }
}
