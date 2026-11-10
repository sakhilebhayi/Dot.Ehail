<?php

namespace App\Domain\Pricing\Quotes;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Data\Market;
use App\Domain\Pricing\Data\PricedRoute;
use App\Domain\Pricing\Data\ZoneRef;
use App\Domain\Pricing\Engine\FareBreakdown;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Domain\Pricing\Models\Promotion;
use Carbon\CarbonInterface;

/**
 * One priced category for one route. `quote` is the persisted, signed FareQuote for bookings; anonymous estimates
 * are never stored (they hold coordinates and bind no one) and have quote = null.
 */
final class PricedOption
{
    /**
     * @param  list<ZoneRef>  $pickupZones
     * @param  list<ZoneRef>  $dropoffZones
     */
    public function __construct(
        public readonly RideCategory $category,
        public readonly PricingRuleSet $ruleSet,
        public readonly Market $market,
        public readonly PricedRoute $route,
        public readonly FareBreakdown $breakdown,
        public readonly array $pickupZones,
        public readonly array $dropoffZones,
        public readonly ?Promotion $promotion,
        public readonly ?string $promotionNote,
        public readonly string $supply,
        public readonly CarbonInterface $pricedAt,
        public ?FareQuote $quote = null,
    ) {}

    public function isEstimate(): bool
    {
        return $this->quote === null;
    }

    /**
     * The shape every client receives (passenger app, public calculator, admin simulator).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'available' => true,
            'kind' => $this->isEstimate() ? 'estimate' : 'quote',
            'quote_id' => $this->quote?->id,
            'expires_at' => $this->quote?->expires_at?->toIso8601String(),
            'category' => $this->category->toPublicArray(),
            'supply' => $this->supply,
            'route' => ['distance_m' => $this->route->distanceM, 'duration_s' => $this->route->durationS, 'provider' => $this->route->provider, 'kind' => 'road'],
            'fare' => $this->breakdown->toArray(),
            'tariff' => ['rule_set_id' => $this->ruleSet->id, 'version' => $this->ruleSet->version, 'name' => $this->ruleSet->name, 'is_example' => $this->ruleSet->is_example],
            'promotion' => $this->promotion === null ? null : ['name' => $this->promotion->name, 'code' => $this->promotion->code],
            'promotion_note' => $this->promotionNote,
            'wording' => $this->isEstimate()
                ? 'Estimate only. The price you book is confirmed when you request the ride. A driver is not guaranteed: who is free nearby is checked when you book.'
                : 'This is the price for this route if you book before the quote expires. It changes only for the reasons listed in the fare rules.',
        ];
    }
}
