<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Observers\RideObserver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Driver-side ride actions: claim an open request, then move it forward
 * through Ride.status's existing lifecycle. RideObserver (already in the
 * codebase, previously with no real trigger for anything but the
 * "completed" transition it watches for its in-app notification) picks up
 * every status change made here automatically -- broadcasting
 * RideStatusUpdated and firing RideCompletedNotification needed no changes.
 */
class RideLifecycleController extends Controller
{
    private const LIFECYCLE = ['accepted', 'en_route', 'arrived', 'in_progress', 'completed'];

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

    public function accept(Ride $ride, Request $request, RideObserver $observer): RedirectResponse
    {
        Gate::authorize('accept', $ride);

        $profile = DriverProfile::withoutGlobalScope('user')
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // WHERE-guarded update, not a plain save() after the policy check --
        // two drivers could pass the policy check for the same still-open
        // ride in the moment before either writes; this only lets the
        // first request through and reports 0 affected rows to the loser,
        // matching CartController::checkout()'s stock-decrement pattern on
        // Dot.Emall this session.
        $claimed = Ride::where('id', $ride->id)
            ->where('status', 'requested')
            ->whereNull('driver_id')
            ->update([
                'driver_id' => $request->user()->id,
                'vehicle_id' => $profile->activeVehicle()?->id,
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

        if ($claimed === 0) {
            return redirect()->route('rides.available')->withErrors(['ride' => 'That ride was just claimed by another driver.']);
        }

        // A query-builder update never fires model events, so RideObserver
        // did not run. Reload the claimed row and publish the same status
        // change (live broadcast to the passenger) the observer would have.
        $observer->publishStatusChange($ride->fresh());

        return redirect()->route('rides.show', $ride)->with('status', 'Ride accepted.');
    }

    /**
     * Forward-only through Ride.status's existing enum -- a completed or
     * cancelled ride is final, and the driver can't walk a stage backward.
     * Same ladder shape as SellerOrderController::updateItemStatus() on
     * Dot.Emall this session.
     */
    public function advance(Ride $ride, Request $request): RedirectResponse
    {
        Gate::authorize('advance', $ride);

        $validated = $request->validate([
            'status' => ['required', Rule::in(self::LIFECYCLE)],
        ]);

        $currentIndex = array_search($ride->status, self::LIFECYCLE, true);
        $nextIndex = array_search($validated['status'], self::LIFECYCLE, true);

        if ($currentIndex === false || $nextIndex <= $currentIndex) {
            return back()->withErrors(['status' => 'That status change is not allowed.']);
        }

        // A completed ride must carry a final fare, and the estimate is the
        // only fare this app can produce (see below) -- so a ride that was
        // requested without coordinates (no estimate) cannot be completed
        // with a NULL fare that would silently drop out of revenue.
        if ($validated['status'] === 'completed' && $ride->estimated_fare === null) {
            return back()->withErrors(['status' => 'This ride has no fare estimate, so it cannot be completed.']);
        }

        $update = ['status' => $validated['status']];
        if ($validated['status'] === 'completed') {
            $update['completed_at'] = now();
            // No live meter/GPS tracking exists during a trip (see
            // FareCalculator's doc comment) -- the estimate at request
            // time is the only fare figure this app can honestly produce,
            // so it becomes final rather than being silently recomputed.
            $update['final_fare'] = $ride->estimated_fare;
        }

        $ride->update($update);

        return back()->with('status', 'Ride updated.');
    }

    public function cancel(Ride $ride): RedirectResponse
    {
        Gate::authorize('cancel', $ride);

        $ride->update(['status' => 'cancelled']);

        return redirect()->route('rides.show', $ride)->with('status', 'Ride cancelled.');
    }
}
