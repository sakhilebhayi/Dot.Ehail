<?php

namespace App\Http\Controllers\Ehail;

use App\Enums\CancelReason;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Services\Ehail\InvalidRideTransition;
use App\Services\Ehail\RideLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Driver-side ride actions: claim an open request, then move it forward
 * through Ride.status. Policies decide who may try; RideLifecycle (backed by
 * the App\Enums\RideStatus transition table) decides whether the state change
 * is legal and performs it, so a stage can never be skipped from here.
 */
class RideLifecycleController extends Controller
{
    /**
     * Open requests any online, approved driver with an active vehicle can
     * claim -- there's no live driver-location data anywhere in this
     * schema (see FareCalculator's own doc comment on why distance is
     * haversine-only), so "nearest driver" dispatch isn't something this
     * app can honestly do yet. A shared claim queue, first driver to
     * accept wins, is the real mechanism this data model supports today.
     */
    public function available(Request $request): View
    {
        $profile = DriverProfile::withoutGlobalScope('user')
            ->where('user_id', $request->user()->id)
            ->first();

        $canAcceptRides = $profile !== null
            && $profile->status === 'approved'
            && $profile->is_online
            && $profile->activeVehicle() !== null;

        $rides = $canAcceptRides
            // Only what a driver needs to decide whether to claim: no
            // passenger relation, no passenger id, no coordinates.
            ? Ride::select(['id', 'status', 'pickup_address', 'dropoff_address', 'vehicle_type', 'estimated_fare', 'created_at'])
                ->where('status', 'requested')->whereNull('driver_id')->latest()->get()
            : collect();

        return view('rides.available', [
            'rides' => $rides,
            'canAcceptRides' => $canAcceptRides,
            'profile' => $profile,
        ]);
    }

    public function accept(Ride $ride, Request $request, RideLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('accept', $ride);

        try {
            $lifecycle->accept($ride, $request->user());
        } catch (InvalidRideTransition $e) {
            return redirect()->route('rides.available')->withErrors(['ride' => $e->getMessage()]);
        }

        return redirect()->route('rides.show', $ride)->with('status', 'Ride accepted.');
    }

    /**
     * One stage at a time: accepted -> en_route -> arrived -> in_progress ->
     * completed. The table in RideStatus is what refuses a skipped or
     * backward move.
     */
    public function advance(Ride $ride, Request $request, RideLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('advance', $ride);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                RideStatus::EnRoute->value, RideStatus::Arrived->value,
                RideStatus::InProgress->value, RideStatus::Completed->value,
            ])],
        ]);

        try {
            $lifecycle->advance($ride, $request->user(), RideStatus::from($validated['status']));
        } catch (InvalidRideTransition $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Ride updated.');
    }

    /**
     * Passenger: cancel. Driver on an accepted ride: back out, and the ride
     * returns to the queue for another driver. The reason must be one that
     * fits the person cancelling.
     */
    public function cancel(Ride $ride, Request $request, RideLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('cancel', $ride);

        $role = $ride->actorFor($request->user());
        $allowed = array_map(fn (CancelReason $r) => $r->value, CancelReason::forActor($role));

        $validated = $request->validate([
            'reason' => ['required', 'string', Rule::in($allowed)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $lifecycle->cancel($ride, $request->user(), CancelReason::from($validated['reason']), $validated['note'] ?? null);
        } catch (InvalidRideTransition $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return $role->value === 'driver'
            ? redirect()->route('rides.available')->with('status', 'You left the ride. It has gone back to the queue.')
            : redirect()->route('rides.show', $ride)->with('status', 'Ride cancelled.');
    }
}
