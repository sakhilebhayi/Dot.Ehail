<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\GatewayResult;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\Refund;

/**
 * Cash handed to the driver. Nothing is collected by software: capture records that cash is due and
 * the driver confirms the amount received (CashCollection). Refunds of cash are made by an operator
 * outside the app and recorded as manual.
 */
class CashGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'cash';
    }

    public function isAvailable(): bool
    {
        return (bool) config('money.cash.enabled');
    }

    public function authorise(?PaymentMethod $method, int $amountMinor, string $currency): GatewayResult
    {
        return $this->isAvailable()
            ? new GatewayResult(GatewayResult::SUCCEEDED)
            : GatewayResult::unavailable('Cash is not accepted on this platform.');
    }

    public function capture(Payment $payment, ?PaymentMethod $method, string $payerEmail): GatewayResult
    {
        return new GatewayResult(GatewayResult::AWAITING_CASH, message: 'Pay the driver in cash.');
    }

    public function verify(Payment $payment): GatewayResult
    {
        return $payment->status === Payment::PAID
            ? new GatewayResult(GatewayResult::SUCCEEDED, amountMinor: $payment->amountMinor(), currency: $payment->currency)
            : new GatewayResult(GatewayResult::AWAITING_CASH);
    }

    public function refund(Refund $refund, Payment $payment): GatewayResult
    {
        // Recorded by the operator who returned the cash or paid it back by EFT.
        return new GatewayResult(GatewayResult::SUCCEEDED, providerId: null, amountMinor: $refund->amount_minor, currency: $refund->currency, code: 'manual');
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        return false;
    }
}
