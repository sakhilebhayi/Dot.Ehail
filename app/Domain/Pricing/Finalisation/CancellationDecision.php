<?php

namespace App\Domain\Pricing\Finalisation;

use App\Domain\Pricing\Money\Money;

/**
 * code: no_fee_not_passenger | no_snapshot | before_driver_assigned | within_free_window | trip_not_cancellable |
 * cancellation_fee | no_show_fee | no_fee_configured
 */
final readonly class CancellationDecision
{
    public function __construct(public ?Money $fee, public string $code, public string $text) {}

    public function charges(): bool
    {
        return $this->fee !== null && $this->fee->isPositive();
    }

    /**
     * @return array{fee: ?Money, code: string, text: string}
     */
    public function toArray(): array
    {
        return ['fee' => $this->fee, 'code' => $this->code, 'text' => $this->text];
    }
}
