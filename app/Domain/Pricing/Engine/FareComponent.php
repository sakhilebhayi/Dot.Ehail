<?php

namespace App\Domain\Pricing\Engine;

use App\Domain\Pricing\Money\Money;

/**
 * One line of a fare: a stable code (for machines and commission rules), a label and a detail (for people) and a
 * signed amount in minor units. Discounts and downward adjustments are negative.
 */
final readonly class FareComponent
{
    public function __construct(
        public string $code,
        public string $label,
        public int $amountMinor,
        public ?string $detail = null,
    ) {}

    /**
     * @return array{code: string, label: string, amount_minor: int, detail: ?string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'label' => $this->label, 'amount_minor' => $this->amountMinor, 'detail' => $this->detail];
    }

    /**
     * @param  array{code: string, label: string, amount_minor: int, detail?: ?string}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self((string) $row['code'], (string) $row['label'], (int) $row['amount_minor'], $row['detail'] ?? null);
    }

    public function money(string $currency): Money
    {
        return Money::of($this->amountMinor, $currency);
    }
}
