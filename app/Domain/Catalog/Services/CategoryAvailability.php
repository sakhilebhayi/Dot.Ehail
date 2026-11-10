<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Contracts\EligibleSupplyCounter;
use App\Domain\Pricing\Contracts\PricingZoneLookup;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Data\Market;
use App\Domain\Pricing\Data\ZoneRef;
use App\Domain\Pricing\Engine\MarketResolver;
use App\Domain\Pricing\Engine\RuleSetResolver;
use Carbon\CarbonInterface;

/**
 * Which categories a passenger can choose here, now: active ride categories offered in this service area at this
 * time, with a published tariff in force, and not known to have zero eligible drivers in range. Supply comes from
 * the dispatch/geo seam EligibleSupplyCounter; until it is bound every offer says supply "unknown".
 */
final class CategoryAvailability
{
    public function __construct(
        private MarketResolver $markets,
        private PricingZoneLookup $zones,
        private RuleSetResolver $ruleSets,
        private EligibleSupplyCounter $supply,
    ) {}

    /**
     * @return list<CategoryOffer> in display order; empty when the point is outside every service area
     */
    public function availableAt(GeoPoint|int $where, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $point = $where instanceof GeoPoint ? $where : null;
        $market = $point !== null ? $this->markets->forPoint($point) : $this->markets->forServiceArea($where);

        if ($market === null) {
            return [];
        }

        $zones = $point !== null ? $this->zones->zonesFor($point) : [];
        $offers = [];

        foreach (RideCategory::query()->activeRides()->get() as $category) {
            if (! $this->offered($category, $market, $zones, $at)) {
                continue;
            }

            $count = $this->supply->count($category, $point, $market->serviceAreaId(), $at);

            if ($count === 0) {
                continue;
            }

            $offers[] = new CategoryOffer($category, $count === null ? 'unknown' : 'available', $count);
        }

        return $offers;
    }

    /**
     * @param  list<ZoneRef>  $zones
     */
    public function offered(RideCategory $category, Market $market, array $zones, CarbonInterface $at): bool
    {
        return $category->is_active
            && $category->service_kind === 'ride'
            && $category->isOfferedIn($market->serviceAreaId(), $at, $market->timezone)
            && $this->ruleSets->resolve($market, $category, $zones, $at) !== null;
    }
}
