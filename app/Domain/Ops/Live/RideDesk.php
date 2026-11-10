<?php

namespace App\Domain\Ops\Live;

use App\Enums\PaymentStatus;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RidePayment;
use App\Services\Payments\Paystack\PaystackConfig;

/**
 * The ride desk (moved here from the pre-R-46 operator page, OperatorRideController::index, which now redirects):
 * rides needing review, recent expiries, the queue figures, and the fare payments that need an operator. Every
 * figure is a count or a sum over real rows; the fare total says it is not money collected, and "collected" sums
 * only payments Paystack confirmed (integer cents, ride_payments.status = paid).
 */
class RideDesk
{
    public function __construct(private readonly PaystackConfig $paystack) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $openRequests = Ride::whereIn('status', ['requested', 'searching']);
        $alertHours = max(1, (int) config('ehail.unpaid_fare_alert_hours'));
        $collected = RidePayment::where('status', RidePayment::STATUS_PAID);

        return [
            'needsReview' => Ride::where('status', 'needs_review')->with(['passenger', 'driver', 'vehicle'])->orderBy('needs_review_at')->get(),
            'expired' => Ride::where('status', 'expired')->with('passenger')->latest('expired_at')->limit(25)->get(),
            'expiredLastWeek' => Ride::where('status', 'expired')->where('expired_at', '>=', now()->subDays(7))->count(),
            'openRequests' => (clone $openRequests)->count(),
            'oldestOpenRequest' => (clone $openRequests)->min('requested_at'),
            'pendingFleets' => Fleet::where('status', Fleet::STATUS_PENDING)->count(),
            'pendingApplications' => DriverProfile::withoutGlobalScope('user')->where('status', 'pending')->count(),
            'completedCount' => Ride::where('status', 'completed')->whereNotNull('final_fare')->count(),
            'completedFareTotal' => (float) Ride::where('status', 'completed')->whereNotNull('final_fare')->sum('final_fare'),
            'paymentsEnabled' => $this->paystack->canTakePayments(),
            'paymentNotice' => $this->paystack->notice(),
            'testMode' => $this->paystack->isTestMode(),
            'alertHours' => $alertHours,
            'unpaidFares' => Ride::where('status', 'completed')
                ->whereIn('payment_status', PaymentStatus::collectableValues())
                ->where('completed_at', '<', now()->subHours($alertHours))
                ->with('passenger')
                ->orderBy('completed_at')
                ->limit(50)
                ->get(),
            'unmatchedPayments' => RidePayment::awaitingReview()->with('ride')->latest('id')->limit(25)->get(),
            'waivedFares' => Ride::where('payment_status', PaymentStatus::Waived->value)->with('waivedBy')->latest('fare_waived_at')->limit(10)->get(),
            'collectedCents' => (int) (clone $collected)->sum('amount_cents'),
            'collectedCount' => (clone $collected)->count(),
        ];
    }
}
