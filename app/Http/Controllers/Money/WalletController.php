<?php

namespace App\Http\Controllers\Money;

use App\Domain\Payments\ChargeAttempts;
use App\Domain\Payments\Exceptions\PaymentMethodRefused;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\RidePaymentChoice;
use App\Domain\Payments\PaymentDueGuard;
use App\Domain\Payments\PaymentMethods;
use App\Domain\Payments\Seams\BusinessBilling;
use App\Domain\Payments\Seams\FareSource;
use App\Domain\Payments\Settlement;
use App\Enums\PaymentStatus;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Services\Payments\Paystack\PaystackConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Passenger wallet API: saved methods, adding a card, the per-ride choice, paying a declined fare.
 */
class WalletController extends Controller
{
    public function index(Request $request, PaystackConfig $paystack, PaymentDueGuard $due, BusinessBilling $business): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'methods' => PaymentMethod::where('user_id', $user->id)->active()->orderByDesc('is_default')->orderByDesc('id')->get()->map->toPublicArray()->values(),
            'card_payments' => ['available' => $paystack->canTakePayments(), 'test_mode' => $paystack->isTestMode(), 'notice' => $paystack->notice()],
            'cash' => ['enabled' => (bool) config('money.cash.enabled'), 'categories' => config('money.cash.categories')],
            'business_accounts' => ['available' => $business->isAvailable()],
            'card_setup_charge_minor' => (int) config('money.card_setup.amount_minor'),
            'payment_due' => $due->statusFor($user),
        ]);
    }

    public function startCard(Request $request, PaymentMethods $methods): JsonResponse
    {
        try {
            $started = $methods->startCardSetup($request->user(), route('money.cards.callback'));
        } catch (PaymentMethodRefused|PaymentGatewayException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'authorization_url' => $started['authorization_url'],
            'reference' => $started['payment']->reference,
            'charge_minor' => $started['payment']->amountMinor(),
            'note' => 'A small verification charge is made to save the card and is refunded straight away.',
        ], 201);
    }

    /**
     * Paystack sends the payer back here. The query string is ignored except to find which of OUR
     * setup attempts to verify (the latest open one of this user).
     */
    public function cardCallback(Request $request, PaymentMethods $methods): RedirectResponse
    {
        $payment = Payment::where('user_id', $request->user()->id)->where('purpose', Payment::PURPOSE_CARD_SETUP)
            ->where('reference', (string) $request->query('reference', ''))->first()
            ?? Payment::where('user_id', $request->user()->id)->where('purpose', Payment::PURPOSE_CARD_SETUP)->latest('id')->first();

        $to = (string) config('money.card_setup.return_to', '/dashboard');

        if ($payment === null) {
            return redirect($to)->with('flash.banner', 'No card setup was found.')->with('flash.bannerStyle', 'danger');
        }

        try {
            $result = $methods->completeCardSetup($payment);
        } catch (PaymentGatewayException $e) {
            return redirect($to)->with('flash.banner', $e->getMessage())->with('flash.bannerStyle', 'danger');
        }

        [$message, $style] = match ($result['status']) {
            'saved' => ['Card saved: '.$result['method']->maskedLabel().'. The verification charge is being refunded.', 'success'],
            'not_reusable' => ['Paystack did not allow this card to be saved for later payments. The verification charge is being refunded.', 'danger'],
            'failed' => ['The card could not be verified. Nothing was saved.', 'danger'],
            default => ['Paystack has not confirmed the card yet. It will appear in your wallet once confirmed.', 'warning'],
        };

        return redirect($to)->with('flash.banner', $message)->with('flash.bannerStyle', $style);
    }

    public function makeDefault(Request $request, PaymentMethod $method, PaymentMethods $methods): JsonResponse
    {
        $this->authorizeOwn($request, $method);
        $methods->setDefault($method);

        return response()->json(['method' => $method->fresh()->toPublicArray()]);
    }

    public function destroy(Request $request, PaymentMethod $method, PaymentMethods $methods): JsonResponse
    {
        $this->authorizeOwn($request, $method);
        $methods->remove($method);

        return response()->json(['removed' => true]);
    }

    public function choose(Request $request, Ride $ride, PaymentMethods $methods): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in([RidePaymentChoice::CARD, RidePaymentChoice::CASH, RidePaymentChoice::BUSINESS_ACCOUNT])],
            'payment_method_id' => ['nullable', 'integer'],
        ]);

        abort_unless($ride->passenger_id === $request->user()->id, 403);

        try {
            $choice = $methods->chooseForRide($ride, $request->user(), $data['method'], $data['payment_method_id'] ?? null);
        } catch (PaymentMethodRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ride_id' => $ride->id, 'method' => $choice->method, 'payment_method_id' => $choice->payment_method_id]);
    }

    /**
     * Pay a declined or unpaid fare now with a saved card.
     */
    public function payNow(Request $request, Ride $ride, PaymentMethods $methods, ChargeAttempts $charges, FareSource $fares): JsonResponse
    {
        $data = $request->validate(['payment_method_id' => ['required', 'integer']]);
        abort_unless($ride->passenger_id === $request->user()->id, 403);

        $method = PaymentMethod::where('user_id', $request->user()->id)->active()->find($data['payment_method_id']);

        if ($method === null || $method->type !== PaymentMethod::CARD || ! $method->isChargeable()) {
            return response()->json(['message' => 'Choose a saved card that can be charged.'], 422);
        }

        if ($ride->status !== 'completed' || ! in_array($ride->paymentStatusEnum(), [PaymentStatus::Failed, PaymentStatus::Unpaid], true)) {
            return response()->json(['message' => 'Nothing is due for this ride.'], 422);
        }

        $fare = $fares->finalFareFor($ride);

        if ($fare === null || $fare->payableMinor() <= 0) {
            return response()->json(['message' => 'Nothing is due for this ride.'], 422);
        }

        // A queued automatic retry is replaced by this payment.
        Payment::where('ride_id', $ride->id)->where('purpose', Payment::PURPOSE_RIDE_FARE)->whereNotNull('next_retry_at')->update(['next_retry_at' => null]);
        app(Settlement::class)->recogniseTrip($ride);

        $payment = $charges->charge($ride, Payment::PURPOSE_RIDE_FARE, $fare->payableMinor(), $fare->currency, 'card', $method);

        return response()->json([
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'message' => match ($payment->status) {
                Payment::PAID => 'Paid. Thank you.',
                Payment::FAILED => (string) ($payment->failure_message ?: 'The card was declined.'),
                default => 'Waiting for the bank to confirm. This page updates when it does.',
            },
        ], $payment->status === Payment::FAILED ? 422 : 200);
    }

    public function paymentDue(Request $request, PaymentDueGuard $due): JsonResponse
    {
        return response()->json($due->statusFor($request->user()));
    }

    private function authorizeOwn(Request $request, PaymentMethod $method): void
    {
        abort_unless($method->user_id === $request->user()->id && $method->status === 'active', 404);
    }
}
