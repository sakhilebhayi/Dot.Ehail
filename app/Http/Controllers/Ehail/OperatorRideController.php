<?php

namespace App\Http\Controllers\Ehail;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RidePayment;
use App\Services\Ehail\InvalidRideTransition;
use App\Services\Ehail\PaymentNotPossible;
use App\Services\Ehail\RideLifecycle;
use App\Services\Ehail\RidePayments;
use App\Services\Payments\Paystack\PaystackConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Operator desk for rides that did not finish on their own: trips flagged
 * needs_review by ehail:expire-rides, and requests that expired. Routes sit
 * behind the `operator` middleware. Every figure on the page is a count or a
 * sum over real rows. The fare total is a sum of recorded fares and says it
 * is not money collected; "collected" is a separate figure, summed only over
 * payments Paystack confirmed (integer cents, ride_payments.status = paid).
 */
class OperatorRideController extends Controller
{
    public function index(PaystackConfig $paystack): View
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

        $alertHours = max(1, (int) config('ehail.unpaid_fare_alert_hours'));
        $collected = RidePayment::where('status', RidePayment::STATUS_PAID);

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
            'paymentsEnabled' => $paystack->canTakePayments(),
            'paymentNotice' => $paystack->notice(),
            'testMode' => $paystack->isTestMode(),
            'alertHours' => $alertHours,
            'unpaidFares' => Ride::where('status', 'completed')
                ->whereIn('payment_status', PaymentStatus::collectableValues())
                ->where('completed_at', '<', now()->subHours($alertHours))
                ->with('passenger')
                ->orderBy('completed_at')
                ->limit(50)
                ->get(),
            'unmatchedPayments' => RidePayment::awaitingReview()->with('ride')->latest('id')->limit(25)->get(),
            'waivedFares' => Ride::where('payment_status', PaymentStatus::Waived->value)
                ->with('waivedBy')
                ->latest('fare_waived_at')
                ->limit(10)
                ->get(),
            'collectedCents' => (int) (clone $collected)->sum('amount_cents'),
            'collectedCount' => (clone $collected)->count(),
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

    /**
     * Write a fare off. The reason is mandatory and the waiver is audited.
     */
    public function waive(Ride $ride, Request $request, RidePayments $payments): RedirectResponse
    {
        Gate::authorize('waive', $ride);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $payments->waive($ride, $request->user(), trim($validated['reason']));
        } catch (PaymentNotPossible $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->route('operator.rides.index')->with('status', "Fare for ride #{$ride->id} waived.");
    }

    /**
     * Record that an unmatched Paystack charge was looked at, and what was done about it.
     */
    public function reviewPayment(RidePayment $payment, Request $request, RidePayments $payments): RedirectResponse
    {
        abort_unless($payment->status === RidePayment::STATUS_UNMATCHED, 404);

        $validated = $request->validate(['note' => ['required', 'string', 'max:500']]);

        try {
            $payments->markReviewed($payment, $request->user(), trim($validated['note']));
        } catch (PaymentNotPossible $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->route('operator.rides.index')->with('status', 'Payment marked as reviewed.');
    }
}
