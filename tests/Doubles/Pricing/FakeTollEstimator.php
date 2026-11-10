<?php

namespace Tests\Doubles\Pricing;

use App\Domain\Pricing\Contracts\TollEstimator;
use App\Domain\Pricing\Data\PricedRoute;

final class FakeTollEstimator implements TollEstimator
{
    public function __construct(public ?int $minor) {}

    public function estimateMinor(PricedRoute $route, string $currency): ?int
    {
        return $this->minor;
    }
}
