<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Fleet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class FleetController extends Controller
{
    /**
     * DriverProfile carries HasUserScope (app/Models/Concerns/HasUserScope.php),
     * which filters every query to the authenticated user's own row. A fleet
     * owner reviewing someone else's application must bypass that scope
     * explicitly -- exactly the pattern routes/web.php's dashboard closure
     * already uses.
     */
    public function show(Fleet $fleet): View
    {
        Gate::authorize('view', $fleet);

        $driverProfiles = DriverProfile::withoutGlobalScope('user')
            ->where('fleet_id', $fleet->id)
            ->with(['user', 'vehicles'])
            ->latest()
            ->get();

        return view('fleets.show', [
            'fleet' => $fleet,
            'pending' => $driverProfiles->where('status', 'pending'),
            'reviewed' => $driverProfiles->whereIn('status', ['approved', 'suspended', 'rejected']),
        ]);
    }

    /**
     * $driverProfileId is deliberately an int, not an implicitly-bound
     * DriverProfile $driverProfile -- Laravel resolves implicit bindings
     * with the model's default query *before* the controller method runs,
     * which still applies HasUserScope and would 404 for any driver
     * profile that isn't the current request's own user_id (i.e. every
     * application a fleet owner is reviewing). withoutGlobalScope('user')
     * below is the only place this can be bypassed.
     */
    public function approveDriver(Fleet $fleet, int $driverProfileId, Request $request): RedirectResponse
    {
        Gate::authorize('view', $fleet);

        $driverProfile = DriverProfile::withoutGlobalScope('user')
            ->where('fleet_id', $fleet->id)
            ->findOrFail($driverProfileId);

        if ($driverProfile->status !== 'pending') {
            return back()->withErrors(['status' => 'Only a pending application can be approved.']);
        }

        $driverProfile->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Driver approved.');
    }

    public function rejectDriver(Fleet $fleet, int $driverProfileId, Request $request): RedirectResponse
    {
        Gate::authorize('view', $fleet);

        $driverProfile = DriverProfile::withoutGlobalScope('user')
            ->where('fleet_id', $fleet->id)
            ->findOrFail($driverProfileId);

        if ($driverProfile->status !== 'pending') {
            return back()->withErrors(['status' => 'Only a pending application can be rejected.']);
        }

        $validated = $request->validate(['reason' => 'required|string']);

        $driverProfile->update([
            'status' => 'rejected',
            'rejected_reason' => $validated['reason'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Driver rejected.');
    }
}
