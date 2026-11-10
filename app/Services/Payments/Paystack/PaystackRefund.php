<?php

namespace App\Services\Payments\Paystack;

use App\Exceptions\Payments\PaymentGatewayException;

/**
 * A Paystack refund (POST /refund, GET /refund/{id}, refund.* webhooks). Status values Paystack
 * reports: pending, processing, processed, failed (and needs-attention, treated as failed for ops).
 */
final readonly class PaystackRefund
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public ?string $transactionReference,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws PaymentGatewayException
     */
    public static function fromData(array $data): self
    {
        $id = $data['id'] ?? $data['refund_reference'] ?? null;
        $amount = $data['amount'] ?? null;
        $status = $data['status'] ?? null;

        if (! (is_int($id) || (is_string($id) && $id !== '')) || ! (is_int($amount) || (is_string($amount) && ctype_digit($amount))) || ! is_string($status)) {
            throw PaymentGatewayException::because('invalid_response', 'Paystack sent a refund we could not read.');
        }

        $transaction = $data['transaction'] ?? null;
        $reference = is_array($transaction) ? ($transaction['reference'] ?? null) : ($data['transaction_reference'] ?? null);

        return new self(
            id: (string) $id,
            status: strtolower($status),
            amountMinor: (int) $amount,
            currency: strtoupper((string) ($data['currency'] ?? '')),
            transactionReference: is_string($reference) ? $reference : null,
        );
    }
}
