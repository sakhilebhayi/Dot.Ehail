<?php

namespace App\Domain\Pricing\Contracts;

use App\Domain\Pricing\Data\PricedRoute;

/**
 * Estimated tolls on a route in minor units; null = unknown (no toll data source configured).
 */
interface TollEstimator
{
    public function estimateMinor(PricedRoute $route, string $currency): ?int;
}
