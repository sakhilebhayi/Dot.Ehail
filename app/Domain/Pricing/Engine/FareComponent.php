<?php

namespace App\Domain\Pricing\Engine;

use App\Domain\Pricing\Money\IntegerMath;
use App\Domain\Pricing\Money\Money;
use App\Domain\Pricing\Money\RoundingMode;

/**
 * One line of a fare: a stable code (for machines and commission rules), a label and a detail (for people) and a
 * signed amount in minor units. Discounts and downward adjustments are negative.
 *
 * A metered line (distance, time, waiting) also carries the quantity it was billed on, in tenths of the unit, and
 * the rate per unit, so a reader (and the invariant tests) can redo the multiplication shown in the detail:
 * amount = rate x quantity, rounded to the cent (D-1900).
 */
final readonly class FareComponent
{
    public function __construct(
        public string $code,
        public string $label,
        public int $amountMinor,
        public ?string $detail = null,
        public ?int $quantityTenths = null,
        public ?string $unit = null,
        public ?int $rateMinor = null,
    ) {}

    public function isMetered(): bool
    {
        return $this->quantityTenths !== null && $this->rateMinor !== null;
    }

    /**
     * The amount the shown multiplication gives: rate x quantity, rounded to the cent with the tariff's mode.
     */
    public static function meteredAmount(int $rateMinor, int $quantityTenths, RoundingMode $mode = RoundingMode::HalfUp): int
    {
        return IntegerMath::divide(IntegerMath::multiply($rateMinor, $quantityTenths), 10, $mode);
    }

    /** "5.7" from 57 tenths; "11" from 110. */
    public static function quantityText(int $tenths): string
    {
        $whole = intdiv(abs($tenths), 10);
        $fraction = abs($tenths) % 10;

        return ($tenths < 0 ? '-' : '').$whole.($fraction === 0 ? '' : '.'.$fraction);
    }

    /**
     * @return array{code: string, label: string, amount_minor: int, detail: ?string, quantity?: string, quantity_tenths?: int, unit?: string, rate_minor?: int}
     */
    public function toArray(): array
    {
        $row = ['code' => $this->code, 'label' => $this->label, 'amount_minor' => $this->amountMinor, 'detail' => $this->detail];

        if ($this->isMetered()) {
            $row += ['quantity' => self::quantityText($this->quantityTenths), 'quantity_tenths' => $this->quantityTenths, 'unit' => $this->unit, 'rate_minor' => $this->rateMinor];
        }

        return $row;
    }

    /**
     * @param  array{code: string, label: string, amount_minor: int, detail?: ?string, quantity_tenths?: ?int, unit?: ?string, rate_minor?: ?int}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) $row['code'],
            (string) $row['label'],
            (int) $row['amount_minor'],
            $row['detail'] ?? null,
            isset($row['quantity_tenths']) ? (int) $row['quantity_tenths'] : null,
            isset($row['unit']) ? (string) $row['unit'] : null,
            isset($row['rate_minor']) ? (int) $row['rate_minor'] : null,
        );
    }

    public function money(string $currency): Money
    {
        return Money::of($this->amountMinor, $currency);
    }
}
