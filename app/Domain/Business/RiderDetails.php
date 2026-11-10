<?php

namespace App\Domain\Business;

use App\Domain\Business\Models\BusinessTrip;

/**
 * For the driver app and dispatch (seam, docs/R46_BUSINESS.md section 6): when a business ride was
 * booked for someone else, the driver needs the rider's name and phone instead of the booker's.
 * Consumers must only show this to the driver assigned to the ride, while the ride holds the driver.
 * The phone is wiped after the retention period (business:sweep).
 */
class RiderDetails
{
    /**
     * @return array{on_behalf: bool, guest: bool, name: string, phone: ?string, account: string}|null
     */
    public function forRide(int $rideId): ?array
    {
        $trip = BusinessTrip::with('account')->where('ride_id', $rideId)->first();

        if ($trip === null || $trip->rider_name === null) {
            return null;
        }

        return [
            'on_behalf' => true,
            'guest' => (bool) $trip->is_guest,
            'name' => (string) $trip->rider_name,
            'phone' => $trip->rider_phone,
            'account' => $trip->account?->displayName() ?? '',
        ];
    }
}
