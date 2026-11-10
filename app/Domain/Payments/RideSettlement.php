<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\RidePaymentChoice;
use App\Domain\Payments\Seams\FareSource;
use App\Enums\PaymentStatus;
use App\Models\Ride;
use App\Services\Payments\Paystack\PaystackConfig;

/**
 * What happens to money when a trip completes (TripCompleted, or the ride row reaching "completed"):
 *
 *  1. how will it be paid? the ride's choice (card / cash / business account), else the passenger's
 *     default chargeable card, else the Paystack checkout link (legacy flow, payment_status unpaid),
 *     else nothing is collected through the platform (payments off: not_due, no ledger, no earnings);
 *  2. recognise the fare in the ledger and create the driver's pending earning;
 *  3. charge the card / record cash due / bill the business account.
 *
 * Running it twice for the same ride does nothing new (journal keys, earning source key, the
 * attempt rules in ChargeAttempts).
 */
class RideSettlement
{
    public function __construct(
        private Settlement $settlement,
        private ChargeAttempts $charges,
        private FareSource $fares,
        private PaystackConfig $paystack,
    ) {}

    public function onTripCompleted(int $rideId): ?string
    {
        $ride = Ride::find($rideId);

        if ($ride === null || $ride->status !== 'completed' || $ride->driver_id === null) {
            return null;
        }

        if (in_array($ride->paymentStatusEnum(), [PaymentStatus::Paid, PaymentStatus::Waived], true)
            && Payment::where('ride_id', $ride->id)->where('purpose', Payment::PURPOSE_RIDE_FARE)->exists()) {
            return 'settled';
        }

        $fare = $this->fares->finalFareFor($ride);

        if ($fare === null || $fare->payableMinor() <= 0) {
            return 'nothing_due';
        }

        [$route, $method] = $this->routeFor($ride);

        if ($route === 'none') {
            return 'not_collected';
        }

        $this->settlement->recogniseTrip($ride);

        if ($route === 'checkout') {
            // The passenger pays from the ride page (RidePayments); nothing to send now.
            if ($ride->paymentStatusEnum() === PaymentStatus::NotDue) {
                $ride->forceFill(['payment_status' => PaymentStatus::Unpaid->value])->save();
            }

            return 'checkout';
        }

        $payment = $this->charges->charge($ride, Payment::PURPOSE_RIDE_FARE, $fare->payableMinor(), $fare->currency, $route, $method);

        return $payment->status;
    }

    /**
     * @return array{0: 'card'|'cash'|'business_account'|'checkout'|'none', 1: ?PaymentMethod}
     */
    public function routeFor(Ride $ride, ?Payment $failed = null): array
    {
        $choice = RidePaymentChoice::where('ride_id', $ride->id)->first();

        if ($choice?->method === RidePaymentChoice::CASH) {
            return ['cash', null];
        }

        if ($choice?->method === RidePaymentChoice::BUSINESS_ACCOUNT && $choice->paymentMethod !== null) {
            return ['business_account', $choice->paymentMethod];
        }

        $card = $choice?->method === RidePaymentChoice::CARD && $choice->paymentMethod?->isChargeable()
            ? $choice->paymentMethod
            : PaymentMethod::where('user_id', $ride->passenger_id)->active()->where('type', PaymentMethod::CARD)
                ->orderByDesc('is_default')->orderByDesc('id')->get()->first(fn (PaymentMethod $m) => $m->isChargeable());

        if ($card !== null && $this->paystack->canTakePayments()) {
            return ['card', $card];
        }

        return $this->paystack->canTakePayments() ? ['checkout', null] : ['none', null];
    }

    /**
     * For the retry sweeper: the gateway and method to retry a failed charge with (null = no
     * chargeable method; the passenger must act).
     *
     * @return array{0: ?string, 1: ?PaymentMethod}
     */
    public function retryMethod(Ride $ride, Payment $failed): array
    {
        [$route, $method] = $this->routeFor($ride, $failed);

        return in_array($route, ['card', 'business_account'], true) ? [$route, $method] : [null, null];
    }
}
