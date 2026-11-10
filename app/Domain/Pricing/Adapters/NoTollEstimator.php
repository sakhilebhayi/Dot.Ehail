<?php

namespace App\Domain\Pricing\Adapters;

use App\Domain\Pricing\Contracts\TollEstimator;
use App\Domain\Pricing\Data\PricedRoute;

/**
 * No toll data source: estimates are unknown, and pass-through rule sets add actual tolls at finalisation.
 */
final class NoTollEstimator implements TollEstimator
{
    public function estimateMinor(PricedRoute $route, string $currency): ?int
    {
        return null;
    }
}
