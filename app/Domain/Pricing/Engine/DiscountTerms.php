<?php

namespace App\Domain\Pricing\Engine;

/**
 * A discount to apply before tax: a percentage (optionally capped) or a fixed amount. The engine never lets a
 * discount exceed the amount it is taken from, so a fare can never go below zero.
 */
final readonly class DiscountTerms
{
    public function __construct(
        public string $label,
        public ?int $percentBp = null,
        public ?int $amountMinor = null,
        public ?int $capMinor = null,
    ) {}

    public static function fixed(string $label, int $amountMinor): self
    {
        return new self($label, null, $amountMinor);
    }
}
