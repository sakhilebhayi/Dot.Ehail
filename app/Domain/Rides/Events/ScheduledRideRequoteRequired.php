<?php

namespace App\Domain\Rides\Events;

/**
 * A scheduled ride's fresh quote is outside the price guarantee; the passenger must accept it before dispatch.
 */
final class ScheduledRideRequoteRequired extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.scheduled_requote_required';
    }
}
