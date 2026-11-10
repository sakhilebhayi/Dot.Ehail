<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\GatewayResult;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\Refund;

/**
 * No payment provider: every operation reports "unavailable". Never pretends to collect money.
 */
class NullGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'null';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function authorise(?PaymentMethod $method, int $amountMinor, string $currency): GatewayResult
    {
        return GatewayResult::unavailable('No payment provider is configured.');
    }

    public function capture(Payment $payment, ?PaymentMethod $method, string $payerEmail): GatewayResult
    {
        return GatewayResult::unavailable('No payment provider is configured.');
    }

    public function verify(Payment $payment): GatewayResult
    {
        return GatewayResult::unavailable('No payment provider is configured.');
    }

    public function refund(Refund $refund, Payment $payment): GatewayResult
    {
        return GatewayResult::unavailable('No payment provider is configured.');
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        return false;
    }
}
