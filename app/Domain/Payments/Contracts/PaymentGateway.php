<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\GatewayResult;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\Refund;

/**
 * One way of collecting money (contract section 3: paystack, cash, business_account, null).
 *
 * authorise  can this method pay this amount right now? (No hold is placed: Paystack's public API has
 *            no card pre-authorisation/capture for South African cards, see docs/R46_RESEARCH.md.)
 * capture    collect the payment row's amount. Never reports success without the gateway's verified
 *            answer; an unknown outcome is "pending" and is verified later.
 * verify     ask the gateway what happened to a payment (by our reference).
 * refund     return money; returns the provider's refund id and status.
 * verifyWebhook  authenticate a raw webhook body.
 */
interface PaymentGateway
{
    public function name(): string;

    public function isAvailable(): bool;

    public function authorise(?PaymentMethod $method, int $amountMinor, string $currency): GatewayResult;

    public function capture(Payment $payment, ?PaymentMethod $method, string $payerEmail): GatewayResult;

    public function verify(Payment $payment): GatewayResult;

    public function refund(Refund $refund, Payment $payment): GatewayResult;

    public function verifyWebhook(string $rawBody, ?string $signature): bool;
}
