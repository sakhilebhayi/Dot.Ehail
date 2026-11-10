<?php

namespace App\Http\Controllers\Ehail;

use App\Domain\Fleet\DriverApplications;
use App\Domain\Fleet\Exceptions\FleetActionRefused;
use App\Domain\Fleet\FleetAccess;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Vehicle;
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
            ->with(['user', 'vehicles', 'documents'])
            ->latest()
            ->get();

        $vehicles = Gate::allows('viewVehicles', $fleet)
            ? Vehicle::whereIn('driver_profile_id', $driverProfiles->pluck('id'))
                ->with(['driverProfile' => fn ($q) => $q->withoutGlobalScope('user')->with('user')])
                ->orderBy('plate_number')
                ->get()
            : null;

        $access = app(FleetAccess::class);

        return view('fleets.show', [
            'fleet' => $fleet,
            'fleets' => $access->fleetsFor(request()->user()),
            'masked' => $access->seesMaskedIdentity(request()->user(), $fleet),
            'vehicles' => $vehicles,
            'pending' => $driverProfiles->where('status', 'pending'),
            'reviewed' => $driverProfiles->whereIn('status', ['approved', 'suspended', 'rejected']),
        ]);
    }

    /**
     * $driverProfileId is deliberately an int, not an implicitly-bound
     * DriverProfile -- implicit binding would apply HasUserScope and 404
     * every application a reviewer is not the applicant of. The decision
     * itself lives in App\Domain\Fleet\DriverApplications (R-46), shared
     * with the fleet portal: same policy, row lock, audit and notification.
     */
    public function approveDriver(Fleet $fleet, int $driverProfileId, Request $request, DriverApplications $applications): RedirectResponse
    {
        Gate::authorize('view', $fleet);

        return $this->decide(fn () => $applications->approve($fleet, $driverProfileId, $request->user()), 'Driver approved.');
    }

    public function rejectDriver(Fleet $fleet, int $driverProfileId, Request $request, DriverApplications $applications): RedirectResponse
    {
        Gate::authorize('view', $fleet);

        $validated = $request->validate(['reason' => 'required|string']);

        return $this->decide(fn () => $applications->reject($fleet, $driverProfileId, $request->user(), $validated['reason']), 'Driver rejected.');
    }

    private function decide(\Closure $decision, string $done): RedirectResponse
    {
        try {
            $decision();
        } catch (FleetActionRefused $refused) {
            abort_if(in_array($refused->status, [403, 404], true), $refused->status);

            return back()->withErrors(['status' => $refused->getMessage()]);
        }

        return back()->with('status', $done);
    }
}
