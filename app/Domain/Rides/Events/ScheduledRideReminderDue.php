<?php

namespace App\Domain\Rides\Events;

/**
 * A scheduled ride's reminder time arrived (comms delivers it in wave 2).
 */
final class ScheduledRideReminderDue extends RideDomainEvent
{
    public static function name(): string
    {
        return 'ride.scheduled_reminder_due';
    }
}
