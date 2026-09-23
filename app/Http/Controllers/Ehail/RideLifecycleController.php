<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Ride;
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
            ? Ride::where('status', 'requested')->whereNull('driver_id')->with('passenger')->latest()->get()
            : collect();

        return view('rides.available', [
            'rides' => $rides,
            'canAcceptRides' => $canAcceptRides,
            'profile' => $profile,
        ]);
    }

    public function accept(Ride $ride, Request $request): RedirectResponse
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
