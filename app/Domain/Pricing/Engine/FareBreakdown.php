<?php

namespace App\Domain\Pricing\Engine;

use App\Domain\Pricing\Money\IntegerMath;
use App\Domain\Pricing\Money\Money;
use App\Domain\Pricing\Money\MoneyFormatter;

/**
 * An itemised fare. The total is DEFINED as the sum of the components, so "total = sum of components" holds by
 * construction and every rand the passenger pays is on a line they can read.
 */
final readonly class FareBreakdown
{
    /**
     * @param  list<FareComponent>  $components
     * @param  list<string>  $notes  plain-language notes shown with the fare (e.g. how tolls are handled)
     */
    public function __construct(
        public array $components,
        public string $currency,
        public int $demandMultiplierBp = 10_000,
        public int $taxIncludedMinor = 0,
        public array $notes = [],
    ) {}

    public function totalMinor(): int
    {
        return array_reduce($this->components, fn (int $sum, FareComponent $c) => IntegerMath::add($sum, $c->amountMinor), 0);
    }

    public function total(): Money
    {
        return Money::of($this->totalMinor(), $this->currency);
    }

    /**
     * Sum of the components whose code is $code or starts with "$code:".
     */
    public function amountFor(string $code): int
    {
        $sum = 0;

        foreach ($this->components as $component) {
            if ($component->code === $code || str_starts_with($component->code, $code.':')) {
                $sum = IntegerMath::add($sum, $component->amountMinor);
            }
        }

        return $sum;
    }

    /**
     * Same breakdown without the components whose code matches (exactly or as a "code:" prefix).
     *
     * @param  list<string>  $codes
     */
    public function without(array $codes): self
    {
        $kept = array_values(array_filter($this->components, function (FareComponent $c) use ($codes) {
            foreach ($codes as $code) {
                if ($c->code === $code || str_starts_with($c->code, $code.':')) {
                    return false;
                }
            }

            return true;
        }));

        return new self($kept, $this->currency, $this->demandMultiplierBp, $this->taxIncludedMinor, $this->notes);
    }

    /**
     * @return list<array{code: string, label: string, amount_minor: int, detail: ?string}>
     */
    public function componentsArray(): array
    {
        return array_map(fn (FareComponent $c) => $c->toArray(), $this->components);
    }

    /**
     * @param  list<array{code: string, label: string, amount_minor: int, detail?: ?string}>  $rows
     */
    public static function fromComponentsArray(array $rows, string $currency, int $demandMultiplierBp = 10_000): self
    {
        return new self(array_map(fn (array $row) => FareComponent::fromArray($row), $rows), $currency, $demandMultiplierBp);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'components' => array_map(fn (FareComponent $c) => $c->toArray() + ['formatted' => $c->money($this->currency)->format()], $this->components),
            'total' => $this->total(),
            'demand_multiplier' => $this->demandMultiplierBp === 10_000 ? null : MoneyFormatter::decimal($this->demandMultiplierBp, 4).'x',
            'tax_included' => $this->taxIncludedMinor > 0 ? Money::of($this->taxIncludedMinor, $this->currency) : null,
            'notes' => $this->notes,
        ];
    }
}
