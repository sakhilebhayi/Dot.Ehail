<?php

namespace Tests\Doubles\Pricing;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Contracts\EligibleSupplyCounter;
use App\Domain\Pricing\Data\GeoPoint;
use Carbon\CarbonInterface;

final class FakeSupplyCounter implements EligibleSupplyCounter
{
    /**
     * @param  array<string, ?int>  $byCategory  category key => count (missing = $default)
     */
    public function __construct(public array $byCategory = [], public ?int $default = null) {}

    public function count(RideCategory $category, ?GeoPoint $near, ?int $serviceAreaId, CarbonInterface $at): ?int
    {
        return array_key_exists($category->key, $this->byCategory) ? $this->byCategory[$category->key] : $this->default;
    }
}
