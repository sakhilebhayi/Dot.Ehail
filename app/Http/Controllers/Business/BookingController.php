<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\BusinessBookings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Book a ride for a traveller or a guest from the portal. The page drives the same public APIs the passenger
 * app uses: geo search, pricing quotes, POST /business/trips/prepare, POST /api/ehail/rides.
 * The booker is the passenger of record (they hold the trip PIN and tracking); the rider's name and phone
 * go to the driver through RiderDetails.
 */
class BookingController extends BusinessController
{
    public function create(Request $request, BusinessBookings $bookings): View
    {
        $member = $this->member($request, 'ride');

        return view('business.book', [
            'member' => $member,
            'account' => $member->account,
            'can' => $this->can($member),
            'profile' => $bookings->profileArray($member),
        ]);
    }
}
