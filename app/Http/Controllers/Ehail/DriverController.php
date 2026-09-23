<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Ride;
use Illuminate\Http\RedirectResponse;
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

        $driverProfile->load(['user', 'vehicles', 'documents']);

        $rides = Ride::where('driver_id', $driverProfile->user_id)
            ->with(['passenger', 'vehicle'])
            ->latest()
            ->limit(20)
            ->get();

        return view('drivers.show', [
            'driverProfile' => $driverProfile,
            'rides' => $rides,
        ]);
    }

    /**
     * Flip is_online -- previously nothing anywhere in the app ever set
     * this column after profile creation (it defaults to false), so an
     * approved driver had no way to ever become eligible to accept a
     * ride (see RideLifecycleController::available()'s eligibility check).
     */
    public function toggleOnline(DriverProfile $driverProfile): RedirectResponse
    {
        Gate::authorize('update', $driverProfile);

        $driverProfile->update(['is_online' => ! $driverProfile->is_online]);

        return back()->with('status', $driverProfile->is_online ? 'You are now online.' : 'You are now offline.');
    }
}
