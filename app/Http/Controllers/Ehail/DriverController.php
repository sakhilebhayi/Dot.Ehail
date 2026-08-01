<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Ride;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DriverController extends Controller
{
    /**
     * Show a driver's profile and ride history. Authorization is delegated
     * to DriverProfilePolicy, which restricts access to the driver
     * themselves — see the policy's docblock for the gap this closes.
     */
    public function show(DriverProfile $driverProfile): View
    {
        Gate::authorize('view', $driverProfile);

        $driverProfile->load(['user', 'vehicles']);

        $rides = Ride::where('driver_id', $driverProfile->user_id)
            ->with(['passenger', 'vehicle'])
            ->latest()
            ->limit(20)
            ->get();

        return view('drivers.show', [
            'driverProfile' => $driverProfile,
            'rides'         => $rides,
        ]);
    }
}
