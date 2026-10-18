<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Services\Ehail\InvalidRideTransition;
use App\Services\Ehail\RideLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Operator desk for rides that did not finish on their own: trips flagged
 * needs_review by ehail:expire-rides, and requests that expired. Routes sit
 * behind the `operator` middleware. Every figure on the page is a count or a
 * sum over real rows; the fare total is a sum of recorded fares and says it
 * is not money collected, since no payment provider is integrated.
 */
class OperatorRideController extends Controller
{
    public function index(): View
    {
        $needsReview = Ride::where('status', 'needs_review')
            ->with(['passenger', 'driver', 'vehicle'])
            ->orderBy('needs_review_at')
            ->get();

        $expired = Ride::where('status', 'expired')
            ->with('passenger')
            ->latest('expired_at')
            ->limit(25)
            ->get();

        $openRequests = Ride::where('status', 'requested');

        return view('operator.rides', [
            'needsReview' => $needsReview,
            'expired' => $expired,
            'expiredLastWeek' => Ride::where('status', 'expired')->where('expired_at', '>=', now()->subDays(7))->count(),
            'openRequests' => (clone $openRequests)->count(),
            'oldestOpenRequest' => (clone $openRequests)->min('requested_at'),
            'pendingFleets' => Fleet::where('status', Fleet::STATUS_PENDING)->count(),
            'pendingApplications' => DriverProfile::withoutGlobalScope('user')->where('status', 'pending')->count(),
            'completedCount' => Ride::where('status', 'completed')->whereNotNull('final_fare')->count(),
            'completedFareTotal' => (float) Ride::where('status', 'completed')->whereNotNull('final_fare')->sum('final_fare'),
        ]);
    }

    /**
     * Complete a trip nobody finished (or one with no estimate) by entering
     * the fare by hand. The reason is mandatory and the entry is audited.
     */
    public function complete(Ride $ride, Request $request, RideLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('resolve', $ride);

        $validated = $request->validate([
            'final_fare' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $lifecycle->operatorComplete($ride, $request->user(), (float) $validated['final_fare'], $validated['reason']);
        } catch (InvalidRideTransition $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('operator.rides.index')->with('status', "Ride #{$ride->id} completed.");
    }

    public function cancel(Ride $ride, Request $request, RideLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('resolve', $ride);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $lifecycle->operatorCancel($ride, $request->user(), $validated['reason']);
        } catch (InvalidRideTransition $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('operator.rides.index')->with('status', "Ride #{$ride->id} cancelled.");
    }
}
