<?php

namespace App\Domain\Pricing\Finalisation;

use App\Domain\Pricing\Engine\FareBreakdown;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Pricing\Money\Money;

/**
 * The fare a ride is charged: itemised components, the basis (quoted_fare_stands | recalculated |
 * operator_adjusted | cancellation_fee) and every adjustment from the quote with its reason.
 */
final readonly class FinalFare
{
    /**
     * @param  list<FareAdjustment>  $adjustments
     */
    public function __construct(
        public RideFare $rideFare,
        public FareBreakdown $breakdown,
        public string $basis,
        public array $adjustments,
    ) {}

    public static function fromRideFare(RideFare $fare): self
    {
        return new self(
            $fare,
            FareBreakdown::fromComponentsArray($fare->final_components ?? [], $fare->currency, $fare->demand_multiplier_bp),
            (string) $fare->basis,
            array_map(fn (array $a) => FareAdjustment::fromArray($a), $fare->adjustments ?? []),
        );
    }

    public function total(): Money
    {
        return $this->breakdown->total();
    }

    public function quoted(): Money
    {
        return $this->rideFare->estimatedTotal();
    }

    /**
     * What the passenger sees after the trip.
     *
     * @return array<string, mixed>
     */
    public function toPassengerArray(): array
    {
        return [
            'quoted' => $this->quoted(),
            'final' => $this->total(),
            'basis' => $this->basis,
            'fare' => $this->breakdown->toArray(),
            'changes' => array_map(fn (FareAdjustment $a) => ['code' => $a->code, 'text' => $a->text, 'amount' => Money::of($a->amountMinor, $this->breakdown->currency)], $this->adjustments),
        ];
    }
}
