<?php

namespace App\Domain\Pricing\Contracts;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Data\GeoPoint;
use Carbon\CarbonInterface;

/**
 * How many eligible drivers are online in range for a category (dispatch + geo own the answer). null means
 * "unknown": the category is shown with supply "unknown" rather than claimed available or hidden.
 */
interface EligibleSupplyCounter
{
    public function count(RideCategory $category, ?GeoPoint $near, ?int $serviceAreaId, CarbonInterface $at): ?int;
}
