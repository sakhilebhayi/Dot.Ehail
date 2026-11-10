<?php

namespace App\Domain\Pricing\Adapters;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Contracts\EligibleSupplyCounter;
use App\Domain\Pricing\Data\GeoPoint;
use Carbon\CarbonInterface;

/**
 * Until dispatch binds a real counter (online, eligible drivers in range), supply is reported as unknown.
 */
final class UnknownSupplyCounter implements EligibleSupplyCounter
{
    public function count(RideCategory $category, ?GeoPoint $near, ?int $serviceAreaId, CarbonInterface $at): ?int
    {
        return null;
    }
}
