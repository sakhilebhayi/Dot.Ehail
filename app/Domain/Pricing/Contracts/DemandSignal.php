<?php

namespace App\Domain\Pricing\Contracts;

use App\Domain\Catalog\Models\RideCategory;
use Carbon\CarbonInterface;

/**
 * Demand multiplier source in basis points (10 000 = 1.0x). null = no signal, which always means no uplift.
 * Only applied when the rule set enables demand pricing, capped by the rule set, and always shown.
 */
interface DemandSignal
{
    public function multiplierBp(?int $serviceAreaId, RideCategory $category, CarbonInterface $at): ?int;
}
