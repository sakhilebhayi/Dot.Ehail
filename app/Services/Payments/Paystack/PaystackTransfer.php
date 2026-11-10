<?php

namespace App\Services\Payments\Paystack;

use App\Exceptions\Payments\PaymentGatewayException;

/**
 * A Paystack transfer (POST /transfer, GET /transfer/verify/{reference}, transfer.* webhooks).
 * Status: pending, otp, success, failed, reversed (and received/processing on some rails).
 */
final readonly class PaystackTransfer
{
    public function __construct(
        public string $reference,
        public string $status,
        public int $amountMinor,
        public ?string $transferCode,
        public ?string $reason,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws PaymentGatewayException
     */
    public static function fromData(array $data): self
    {
        $reference = $data['reference'] ?? null;
        $status = $data['status'] ?? null;
        $amount = $data['amount'] ?? null;

        if (! is_string($reference) || $reference === '' || ! is_string($status) || ! (is_int($amount) || (is_string($amount) && ctype_digit($amount)))) {
            throw PaymentGatewayException::because('invalid_response', 'Paystack sent a transfer we could not read.');
        }

        return new self(
            reference: $reference,
            status: strtolower($status),
            amountMinor: (int) $amount,
            transferCode: is_string($data['transfer_code'] ?? null) ? $data['transfer_code'] : null,
            reason: is_string($data['gateway_response'] ?? null) ? mb_substr($data['gateway_response'], 0, 200) : (is_string($data['reason'] ?? null) ? mb_substr($data['reason'], 0, 200) : null),
        );
    }
}
