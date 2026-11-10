<?php

namespace App\Http\Controllers\Ehail;

use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Exceptions\DispatchRefused;
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
     * The open board: requests an online, approved driver with an active
     * vehicle can claim once sequential dispatch (App\Domain\Dispatch\Dispatcher)
     * has offered them for ehail_dispatch.open_board_after_seconds without an
     * acceptance, plus address-only requests that cannot be ranked by distance.
     */
    public function available(Request $request, Dispatcher $dispatcher): View
    {
        $profile = DriverProfile::withoutGlobalScope('user')
            ->where('user_id', $request->user()->id)
            ->first();

        $canAcceptRides = $profile !== null
            && $profile->status === 'approved'
            && $profile->is_online
            && $profile->activeVehicle() !== null;

        $vehicle = $canAcceptRides ? $profile->activeVehicle() : null;

        $rides = $canAcceptRides
            // Only what a driver needs to decide whether to claim: no
            // passenger relation, no passenger id, no coordinates -- and only
            // the tiers the active vehicle may serve.
            ? $dispatcher->boardFor($vehicle)
            : collect();

        return view('rides.available', [
            'rides' => $rides,
            'canAcceptRides' => $canAcceptRides,
            'profile' => $profile,
            'vehicle' => $vehicle,
        ]);
    }

    public function accept(Ride $ride, Request $request, RideLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('accept', $ride);

        try {
            $lifecycle->accept($ride, $request->user());
        } catch (InvalidRideTransition|DispatchRefused $e) {
            return redirect()->route('rides.available')->withErrors(['ride' => $e->getMessage()]);
        }

        return redirect()->route('rides.show', $ride)->with('status', 'Ride accepted.');
    }

    /**
     * One stage at a time: driver_assigned -> driver_arriving -> driver_arrived
     * -> in_progress (PIN on rides that carry one) -> completed. The table in RideStatus is what refuses a skipped or
     * backward move.
     */
    public function advance(Ride $ride, Request $request, RideLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('advance', $ride);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                RideStatus::DriverArriving->value, RideStatus::DriverArrived->value,
                RideStatus::InProgress->value, RideStatus::Completed->value,
            ])],
            'pin' => ['nullable', 'string', 'max:8'],
        ]);

        try {
            $lifecycle->advance($ride, $request->user(), RideStatus::from($validated['status']), $validated['pin'] ?? null);
        } catch (DispatchRefused $e) {
            return back()->withErrors(['pin' => $e->getMessage()]);
        } catch (InvalidRideTransition $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', 'Ride updated.');
    }

    /**
     * Passenger: cancel. Driver on an assigned ride: back out, and the ride
     * goes back to searching for another driver. The reason must be one that
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
