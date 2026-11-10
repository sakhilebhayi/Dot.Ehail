<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\GatewayResult;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\Refund;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackConfig;
use App\Services\Payments\Paystack\PaystackTransaction;

/**
 * Card payments through Paystack: charge a saved authorisation server side, then confirm with the
 * verify endpoint before anything is recorded as paid.
 */
class PaystackGateway implements PaymentGateway
{
    public function __construct(private PaystackConfig $config, private PaystackClient $client) {}

    public function name(): string
    {
        return 'paystack';
    }

    public function isAvailable(): bool
    {
        return $this->config->canTakePayments();
    }

    public function authorise(?PaymentMethod $method, int $amountMinor, string $currency): GatewayResult
    {
        return match (true) {
            ! $this->isAvailable() => GatewayResult::unavailable('Card payments are not enabled on this environment.'),
            $method === null || $method->type !== PaymentMethod::CARD => GatewayResult::failed('no_card', 'No saved card to charge.'),
            ! $method->isChargeable() => GatewayResult::failed($method->isExpired() ? 'card_expired' : 'card_not_reusable', $method->isExpired() ? 'The saved card has expired.' : 'The saved card cannot be charged again.'),
            $amountMinor <= 0 => GatewayResult::failed('nothing_due', 'Nothing to charge.'),
            default => new GatewayResult(GatewayResult::SUCCEEDED),
        };
    }

    public function capture(Payment $payment, ?PaymentMethod $method, string $payerEmail): GatewayResult
    {
        $check = $this->authorise($method, $payment->amountMinor(), $payment->currency);

        if (! $check->succeeded()) {
            return $check;
        }

        try {
            $charged = $this->client->chargeAuthorization(
                $payerEmail,
                $payment->amountMinor(),
                $payment->currency,
                (string) $method->authorization_code,
                $payment->reference,
                ['payment_id' => $payment->id, 'ride_id' => (int) $payment->ride_id, 'purpose' => $payment->purpose],
            );
        } catch (PaymentGatewayException $e) {
            // A refusal (4xx) is a decline unless the reference already exists at Paystack, which the
            // verify below settles. A network failure leaves the outcome unknown: verify later.
            if ($e->reason === 'unavailable') {
                return new GatewayResult(GatewayResult::PENDING, code: 'unavailable', message: $e->getMessage());
            }

            if ($e->reason === 'rejected') {
                $verified = $this->verify($payment);

                return $verified->status === GatewayResult::FAILED && $verified->code === 'not_found'
                    ? GatewayResult::failed('declined', 'The card was declined.')
                    : $verified;
            }

            return new GatewayResult(GatewayResult::PENDING, code: $e->reason, message: $e->getMessage());
        }

        if ($charged->status === 'failed' || $charged->status === 'reversed') {
            return $this->fromTransaction($charged, $payment);
        }

        // Paystack said success (or something not final): the verify endpoint is the source of truth.
        return $this->verify($payment);
    }

    public function verify(Payment $payment): GatewayResult
    {
        try {
            $transaction = $this->client->verifyTransaction($payment->reference);
        } catch (PaymentGatewayException $e) {
            return match ($e->reason) {
                'rejected' => GatewayResult::failed('not_found', 'Paystack has no record of this charge.'),
                default => new GatewayResult(GatewayResult::PENDING, code: $e->reason, message: $e->getMessage()),
            };
        }

        return $this->fromTransaction($transaction, $payment);
    }

    public function refund(Refund $refund, Payment $payment): GatewayResult
    {
        if (! $this->isAvailable()) {
            return GatewayResult::unavailable('Paystack is not enabled on this environment.');
        }

        try {
            $result = $this->client->createRefund($payment->reference, $refund->amount_minor, $refund->currency, Refund::REASONS[$refund->reason] ?? $refund->reason);
        } catch (PaymentGatewayException $e) {
            return $e->reason === 'unavailable'
                ? GatewayResult::unavailable($e->getMessage())
                : GatewayResult::failed($e->reason, $e->getMessage());
        }

        return new GatewayResult(
            status: in_array($result->status, ['processed'], true) ? GatewayResult::SUCCEEDED : ($result->status === 'failed' ? GatewayResult::FAILED : GatewayResult::PENDING),
            providerId: $result->id,
            amountMinor: $result->amountMinor,
            currency: $result->currency,
            code: $result->status,
        );
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        try {
            $secret = $this->config->secretKey();
        } catch (PaymentGatewayException) {
            return false;
        }

        $signature = strtolower(trim((string) $signature));

        return $signature !== '' && hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
    }

    /**
     * Success only when Paystack says success AND reference, amount and currency match our row.
     */
    private function fromTransaction(PaystackTransaction $transaction, Payment $payment): GatewayResult
    {
        $summary = $transaction->safePayload('verify');

        if (! $transaction->isSuccessful()) {
            return in_array($transaction->status, ['failed', 'reversed', 'abandoned'], true)
                ? new GatewayResult(GatewayResult::FAILED, $transaction->id, $transaction->amountCents, $transaction->currency, $transaction->channel, 'declined', $transaction->gatewayResponse ?: 'The card was declined.', $summary)
                : new GatewayResult(GatewayResult::PENDING, $transaction->id, $transaction->amountCents, $transaction->currency, $transaction->channel, $transaction->status, null, $summary);
        }

        $mismatch = match (true) {
            $transaction->reference !== $payment->reference => 'reference_mismatch',
            $transaction->currency !== strtoupper($payment->currency) => 'currency_mismatch',
            $transaction->amountCents !== $payment->amountMinor() => 'amount_mismatch',
            default => null,
        };

        if ($mismatch !== null) {
            return new GatewayResult(GatewayResult::FAILED, $transaction->id, $transaction->amountCents, $transaction->currency, $transaction->channel, $mismatch, 'Paystack reported a charge that does not match this payment.', $summary);
        }

        return new GatewayResult(GatewayResult::SUCCEEDED, $transaction->id, $transaction->amountCents, $transaction->currency, $transaction->channel, null, null, $summary, $transaction->authorization, $transaction->reference);
    }
}
