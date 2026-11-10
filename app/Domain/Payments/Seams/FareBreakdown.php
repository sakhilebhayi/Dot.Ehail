<?php

namespace App\Domain\Payments\Seams;

/**
 * The final fare of a trip as the money domain needs it, in integer minor units.
 *
 * grossMinor     the fare before promotions, tax included when the tax mode adds tax
 * promotionMinor discount funded by the platform (positive number)
 * payableMinor   what the passenger owes: gross - promotion
 * taxMinor       tax inside gross (0 unless the operator configured a tax mode)
 * platformFeesMinor fees inside gross kept by the platform and not commissioned (booking fee)
 * components     [{code, label, amount_minor}] lines for the receipt
 * reasons        [{code, label}] why the final fare differs from the estimate
 */
final readonly class FareBreakdown
{
    /**
     * @param  array<int, array{code: string, label: string, amount_minor: int}>  $components
     * @param  array<int, array{code: string, label: string}>  $reasons
     */
    public function __construct(
        public int $grossMinor,
        public string $currency,
        public array $components,
        public int $promotionMinor = 0,
        public int $taxMinor = 0,
        public int $platformFeesMinor = 0,
        public ?int $estimateMinor = null,
        public array $reasons = [],
        public ?int $distanceM = null,
        public ?int $durationS = null,
        public ?string $source = null,
    ) {}

    public function payableMinor(): int
    {
        return max(0, $this->grossMinor - $this->promotionMinor);
    }
}
