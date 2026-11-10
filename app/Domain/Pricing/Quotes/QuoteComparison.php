<?php

namespace App\Domain\Pricing\Quotes;

use App\Domain\Pricing\Data\PricedRoute;

/**
 * All categories priced for one route, plus the categories that could not be priced and why.
 */
final readonly class QuoteComparison
{
    /**
     * @param  list<PricedOption>  $options
     * @param  list<QuoteUnavailable>  $unavailable
     * @param  list<QuoteUnavailable>  $hidden  categories left out because the trip does not fit them (not shown to people)
     */
    public function __construct(public PricedRoute $route, public array $options, public array $unavailable, public array $hidden = []) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'route' => ['distance_m' => $this->route->distanceM, 'duration_s' => $this->route->durationS, 'provider' => $this->route->provider, 'kind' => 'road'],
            'options' => array_map(fn (PricedOption $o) => $o->toArray(), $this->options),
            'unavailable' => array_map(fn (QuoteUnavailable $u) => $u->toArray(), $this->unavailable),
            'hidden' => array_map(fn (QuoteUnavailable $u) => $u->toArray(), $this->hidden),
        ];
    }
}
