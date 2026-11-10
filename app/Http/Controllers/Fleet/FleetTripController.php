<?php

namespace App\Http\Controllers\Fleet;

use App\Domain\Fleet\FleetTrips;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fleet trip history: what a trip record needs and nothing more (the passenger's first name, no addresses).
 */
class FleetTripController extends FleetController
{
    public function index(Request $request, FleetTrips $trips): View
    {
        $fleet = $this->fleet($request, 'trips');
        $filters = [
            'status' => in_array($request->query('status'), ['completed', 'cancelled', 'active'], true) ? $request->query('status') : null,
            'driver' => ctype_digit((string) $request->query('driver')) ? (int) $request->query('driver') : null,
            'vehicle' => ctype_digit((string) $request->query('vehicle')) ? (int) $request->query('vehicle') : null,
        ];

        return view('fleet.trips', [
            'fleet' => $fleet,
            'fleets' => $this->access->fleetsFor($request->user()),
            'trips' => $trips->page($request->user(), $fleet, $filters),
            'filters' => $filters,
            'drivers' => $this->access->drivers($request->user(), $fleet),
            'vehicles' => $this->access->vehicleQuery($request->user(), $fleet)->orderBy('plate_number')->get(['id', 'plate_number']),
        ]);
    }
}
