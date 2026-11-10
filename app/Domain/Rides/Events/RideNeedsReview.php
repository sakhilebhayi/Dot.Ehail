<?php

namespace App\Domain\Rides\Events;

/**
 * The ride is stuck or disputed and waits for an operator.
 */
final class RideNeedsReview extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.needs_review';
    }
}
