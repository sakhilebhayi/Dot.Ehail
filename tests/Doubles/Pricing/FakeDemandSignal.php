<?php

namespace Tests\Doubles\Pricing;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Contracts\DemandSignal;
use Carbon\CarbonInterface;

final class FakeDemandSignal implements DemandSignal
{
    public function __construct(public ?int $bp) {}

    public function multiplierBp(?int $serviceAreaId, RideCategory $category, CarbonInterface $at): ?int
    {
        return $this->bp;
    }
}
