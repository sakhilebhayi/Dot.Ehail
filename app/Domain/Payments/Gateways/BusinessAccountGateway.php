<?php

namespace App\Domain\Payments\Gateways;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\GatewayResult;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Seams\BusinessBilling;

/**
 * Trips billed to a business account (wave 2). Delegates every decision to the BusinessBilling seam;
 * with no business domain bound it is unavailable and nothing is charged.
 */
class BusinessAccountGateway implements PaymentGateway
{
    public function __construct(private BusinessBilling $billing) {}

    public function name(): string
    {
        return 'business_account';
    }

    public function isAvailable(): bool
    {
        return $this->billing->isAvailable();
    }

    public function authorise(?PaymentMethod $method, int $amountMinor, string $currency): GatewayResult
    {
        if (! $this->isAvailable() || $method === null || $method->business_account_id === null) {
            return GatewayResult::unavailable('Business accounts are not available.');
        }

        $decision = $this->billing->canCharge((int) $method->business_account_id, $method->user, $amountMinor, $currency);

        return $decision->allowed ? new GatewayResult(GatewayResult::SUCCEEDED) : GatewayResult::failed('business_declined', $decision->reason);
    }

    public function capture(Payment $payment, ?PaymentMethod $method, string $payerEmail): GatewayResult
    {
        $check = $this->authorise($method, $payment->amountMinor(), $payment->currency);

        if (! $check->succeeded()) {
            return $check;
        }

        $reference = $this->billing->record((int) $method->business_account_id, $method->user, $payment->amountMinor(), $payment->currency, (int) $payment->ride_id);

        return new GatewayResult(GatewayResult::SUCCEEDED, providerId: $reference, amountMinor: $payment->amountMinor(), currency: $payment->currency, channel: 'business_account');
    }

    public function verify(Payment $payment): GatewayResult
    {
        return $payment->status === Payment::PAID ? new GatewayResult(GatewayResult::SUCCEEDED) : new GatewayResult(GatewayResult::PENDING);
    }

    public function refund(Refund $refund, Payment $payment): GatewayResult
    {
        return new GatewayResult(GatewayResult::SUCCEEDED, amountMinor: $refund->amount_minor, currency: $refund->currency, code: 'manual');
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        return false;
    }
}
