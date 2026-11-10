<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Gateways\BusinessAccountGateway;
use App\Domain\Payments\Gateways\CashGateway;
use App\Domain\Payments\Gateways\NullGateway;
use App\Domain\Payments\Gateways\PaystackGateway;

/**
 * Resolves a gateway by driver name (paystack, cash, business_account, null).
 */
class PaymentGateways
{
    public function driver(string $name): PaymentGateway
    {
        return match ($name) {
            'paystack', 'card' => app(PaystackGateway::class),
            'cash' => app(CashGateway::class),
            'business_account' => app(BusinessAccountGateway::class),
            default => app(NullGateway::class),
        };
    }
}
