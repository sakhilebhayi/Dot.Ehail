<?php

namespace App\Domain\Pricing\Adapters;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Contracts\DemandSignal;
use Carbon\CarbonInterface;

/**
 * No demand data source exists, so there is never an uplift. A real signal (open requests vs online drivers per
 * area) can be bound later; the rule set's cap and visibility rules still apply.
 */
final class NoDemandSignal implements DemandSignal
{
    public function multiplierBp(?int $serviceAreaId, RideCategory $category, CarbonInterface $at): ?int
    {
        return null;
    }
}
