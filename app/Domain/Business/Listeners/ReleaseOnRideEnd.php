<?php

namespace App\Domain\Business\Listeners;

use App\Domain\Business\BusinessBookings;
use App\Domain\Rides\Events\RideDomainEvent;

/**
 * Cancelled, expired, failed and no-show rides give their business reservation back (a cancellation
 * or no-show fee, if any, is charged separately through the business_account gateway and counted then).
 */
class ReleaseOnRideEnd
{
    public function __construct(private BusinessBookings $bookings) {}

    public function handle(RideDomainEvent $event): void
    {
        $this->bookings->rideEnded((int) $event->rideId);
    }
}
