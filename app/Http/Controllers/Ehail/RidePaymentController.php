<?php

namespace App\Http\Controllers\Ehail;

use App\Exceptions\Payments\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Services\Ehail\PaymentNotPossible;
use App\Services\Ehail\RidePayments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The passenger paying a completed ride's fare through Paystack. Only the ride's passenger passes
 * the `pay` ability; the amount is read from the stored fare, never from the request, and the return
 * visit is settled by asking Paystack, never by reading the query string.
 */
class RidePaymentController extends Controller
{
    public function store(Ride $ride, Request $request, RidePayments $payments): RedirectResponse
    {
        Gate::authorize('pay', $ride);

        try {
            $payment = $payments->start($ride, $request->user());
        } catch (PaymentNotPossible|PaymentGatewayException $e) {
            return redirect()->route('rides.show', $ride)->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->away($payment->authorization_url);
    }

    public function callback(Ride $ride, RidePayments $payments): RedirectResponse
    {
        Gate::authorize('pay', $ride);

        try {
            $outcome = $payments->confirmReturn($ride);
        } catch (PaymentGatewayException) {
            return redirect()->route('rides.show', $ride)->withErrors([
                'payment' => 'We could not confirm your payment with Paystack just now. Nothing has been changed. If you paid, it will show here shortly, or open this page again in a minute.',
            ]);
        }

        $back = redirect()->route('rides.show', $ride);

        return match ($outcome) {
            RidePayments::PAID => $back->with('status', 'Payment received. Thank you.'),
            RidePayments::PENDING => $back->with('status', 'Your payment is not confirmed yet. If you completed it, this page will show it as paid shortly.'),
            RidePayments::FAILED => $back->withErrors(['payment' => 'The payment did not go through. You have not been charged for this attempt, and you can try again.']),
            RidePayments::UNMATCHED => $back->withErrors(['payment' => 'We received a payment that does not match this fare. Please do not pay again: an operator has been shown it and will sort it out with you.']),
            default => $back,
        };
    }
}
