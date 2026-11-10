<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Exceptions\PaymentMethodRefused;
use App\Domain\Payments\Models\DriverMoneySetting;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\RidePaymentChoice;
use App\Domain\Payments\Seams\BusinessBilling;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\Ride;
use App\Models\User;
use App\Services\Payments\Paystack\PaystackAuthorization;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saved payment methods and per-ride choices.
 *
 * Adding a card (Paystack's documented way to obtain a reusable authorisation): a small card-only
 * checkout (money.card_setup.amount_minor). When verify confirms success and Paystack marks the
 * authorisation reusable, the token is stored (encrypted) with brand/last4/expiry/bank, de-duplicated
 * by Paystack's card signature, and the verification charge is refunded at once.
 */
class PaymentMethods
{
    public function __construct(
        private PaystackConfig $config,
        private PaystackClient $client,
        private Settlement $settlement,
        private BusinessBilling $business,
    ) {}

    /**
     * @return array{payment: Payment, authorization_url: string}
     *
     * @throws PaymentMethodRefused|PaymentGatewayException
     */
    public function startCardSetup(User $user, string $callbackUrl): array
    {
        if (! $this->config->canTakePayments()) {
            throw new PaymentMethodRefused('Card payments are not enabled on this environment.');
        }

        $amount = max(1, (int) config('money.card_setup.amount_minor', 100));

        $payment = Payment::create([
            'user_id' => $user->id,
            'provider' => 'paystack',
            'purpose' => Payment::PURPOSE_CARD_SETUP,
            'attempt' => 1,
            'reference' => 'ehail-card-'.$user->id.'-'.Str::lower(Str::random(14)),
            'amount_cents' => $amount,
            'currency' => Money::currency(),
            'status' => Payment::INITIATED,
            'expires_at' => now()->addMinutes($this->config->attemptTtlMinutes()),
        ]);

        $started = $this->client->initializeTransaction($user->email, $amount, $payment->currency, $payment->reference, $callbackUrl,
            ['payment_id' => $payment->id, 'purpose' => Payment::PURPOSE_CARD_SETUP], ['card']);

        $payment->forceFill(['authorization_url' => $started->authorizationUrl])->save();

        return ['payment' => $payment, 'authorization_url' => $started->authorizationUrl];
    }

    /**
     * Verify a card setup charge with Paystack and save the card. Safe to call from the callback and
     * the webhook, in any order, any number of times.
     *
     * @return array{status: 'saved'|'not_reusable'|'failed'|'pending', method: ?PaymentMethod}
     *
     * @throws PaymentGatewayException when Paystack cannot be reached (nothing changes)
     */
    public function completeCardSetup(Payment $payment): array
    {
        if ($payment->purpose !== Payment::PURPOSE_CARD_SETUP) {
            return ['status' => 'failed', 'method' => null];
        }

        $transaction = $this->client->verifyTransaction($payment->reference);
        $saved = null;
        $outcome = 'pending';
        $justPaid = false;

        DB::transaction(function () use ($payment, $transaction, &$saved, &$outcome, &$justPaid) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Payment::PAID) {
                $saved = PaymentMethod::find($locked->payment_method_id);
                $outcome = $saved === null ? 'not_reusable' : 'saved';

                return;
            }

            if (! $transaction->isSuccessful()) {
                if (in_array($transaction->status, ['failed', 'abandoned', 'reversed'], true)) {
                    $locked->forceFill(['status' => Payment::FAILED, 'failure_code' => 'declined', 'failure_message' => $transaction->gatewayResponse, 'summary' => $transaction->safePayload('verify')])->save();
                    $outcome = 'failed';
                }

                return;
            }

            if ($transaction->reference !== $locked->reference || $transaction->amountCents !== $locked->amountMinor() || $transaction->currency !== $locked->currency) {
                $locked->forceFill(['status' => Payment::UNMATCHED, 'unmatched_reason' => 'amount_mismatch', 'summary' => $transaction->safePayload('verify')])->save();
                $outcome = 'failed';

                return;
            }

            $authorization = $transaction->authorization;
            $user = User::findOrFail($locked->user_id);

            if ($authorization !== null && $authorization->reusable && ($authorization->channel ?? 'card') === 'card') {
                $saved = $this->storeCard($user, $authorization);
            }

            $locked->forceFill([
                'status' => Payment::PAID,
                'payment_method_id' => $saved?->id,
                'provider_transaction_id' => $transaction->id,
                'channel' => $transaction->channel,
                'paid_at' => now(),
                'verified_at' => now(),
                'summary' => $transaction->safePayload('verify'),
            ])->save();

            $outcome = $saved === null ? 'not_reusable' : 'saved';
            $justPaid = true;
        });

        if ($justPaid) {
            $fresh = $payment->fresh();
            $this->settlement->paymentSucceeded($fresh);
            app(Refunds::class)->refundCardSetup($fresh);
        }

        return ['status' => $outcome, 'method' => $saved];
    }

    public function setDefault(PaymentMethod $method): void
    {
        DB::transaction(function () use ($method) {
            PaymentMethod::where('user_id', $method->user_id)->where('id', '!=', $method->id)->update(['is_default' => false]);
            $method->forceFill(['is_default' => true])->save();
        });
    }

    /**
     * Removing a card forgets its token at once (the row is kept for payment history, masked).
     */
    public function remove(PaymentMethod $method): void
    {
        DB::transaction(function () use ($method) {
            $wasDefault = $method->is_default;
            $method->forceFill(['status' => 'removed', 'removed_at' => now(), 'is_default' => false, 'authorization_code' => null])->save();

            if ($wasDefault) {
                $next = PaymentMethod::where('user_id', $method->user_id)->active()->latest('id')->first();
                $next?->forceFill(['is_default' => true])->save();
            }
        });
    }

    /**
     * Whether cash may be used for a ride: the platform accepts cash, the ride's category is in the
     * cash list (empty = all), and, once a driver is assigned, that driver accepts cash.
     */
    public function cashAllowedFor(Ride $ride): bool
    {
        if (! config('money.cash.enabled')) {
            return false;
        }

        $categories = (array) config('money.cash.categories', []);

        if ($categories !== [] && ! in_array($ride->vehicle_type, $categories, true)) {
            return false;
        }

        return $ride->driver_id === null || DriverMoneySetting::acceptsCash($ride->driver_id);
    }

    /**
     * The passenger picks how to pay for a ride (before it completes).
     *
     * @throws PaymentMethodRefused
     */
    public function chooseForRide(Ride $ride, User $passenger, string $method, ?int $paymentMethodId): RidePaymentChoice
    {
        if ($ride->passenger_id !== $passenger->id) {
            throw new PaymentMethodRefused('Only the passenger can choose how to pay.');
        }

        if (in_array($ride->status, ['completed', 'cancelled', 'expired'], true)) {
            throw new PaymentMethodRefused('The payment method can only be changed before the trip ends.');
        }

        $saved = null;

        if ($method === RidePaymentChoice::CASH) {
            if (! $this->cashAllowedFor($ride)) {
                throw new PaymentMethodRefused('Cash is not accepted for this ride.');
            }
        } elseif ($method === RidePaymentChoice::CARD || $method === RidePaymentChoice::BUSINESS_ACCOUNT) {
            $saved = $paymentMethodId === null ? null : PaymentMethod::where('user_id', $passenger->id)->active()->find($paymentMethodId);

            if ($saved === null || $saved->type !== ($method === RidePaymentChoice::CARD ? PaymentMethod::CARD : PaymentMethod::BUSINESS_ACCOUNT)) {
                throw new PaymentMethodRefused('Choose one of your saved payment methods.');
            }

            if ($method === RidePaymentChoice::CARD && ! $saved->isChargeable()) {
                throw new PaymentMethodRefused('That card cannot be charged (expired or not reusable).');
            }

            if ($method === RidePaymentChoice::BUSINESS_ACCOUNT && ! $this->business->isAvailable()) {
                throw new PaymentMethodRefused('Business accounts are not available yet.');
            }
        } else {
            throw new PaymentMethodRefused('Unknown payment method.');
        }

        return RidePaymentChoice::updateOrCreate(
            ['ride_id' => $ride->id],
            ['passenger_id' => $passenger->id, 'method' => $method, 'payment_method_id' => $saved?->id, 'business_account_id' => $saved?->business_account_id],
        );
    }

    /**
     * De-duplicate by Paystack's card signature: the same card added twice refreshes the stored token.
     */
    private function storeCard(User $user, PaystackAuthorization $authorization): PaymentMethod
    {
        $existing = $authorization->signature === null ? null : PaymentMethod::where('user_id', $user->id)
            ->where('provider', 'paystack')->where('signature', $authorization->signature)->first();

        $attributes = [
            'type' => PaymentMethod::CARD,
            'provider' => 'paystack',
            'authorization_code' => $authorization->authorizationCode,
            'signature' => $authorization->signature,
            'brand' => $authorization->brand,
            'last4' => $authorization->last4,
            'exp_month' => $authorization->expMonth,
            'exp_year' => $authorization->expYear,
            'bank' => $authorization->bank,
            'country_code' => $authorization->countryCode,
            'reusable' => true,
            'status' => 'active',
            'removed_at' => null,
        ];

        $hasDefault = PaymentMethod::where('user_id', $user->id)->active()->where('is_default', true)->exists();

        if ($existing !== null) {
            $existing->forceFill($attributes + ['is_default' => $existing->is_default || ! $hasDefault])->save();

            return $existing;
        }

        return PaymentMethod::create($attributes + ['user_id' => $user->id, 'is_default' => ! $hasDefault]);
    }
}
