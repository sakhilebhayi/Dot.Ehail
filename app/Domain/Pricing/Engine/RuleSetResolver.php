<?php

namespace App\Domain\Pricing\Engine;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Data\Market;
use App\Domain\Pricing\Data\ZoneRef;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Domain\Pricing\Support\TimeWindows;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which published rule set prices a trip. Candidates must match the market's country and currency, be in force at
 * the moment (published, effective, not yet retired), and fit the trip (area, pickup zone, category, time window;
 * a null constraint fits everything). The most specific candidate wins:
 *
 *   pickup zone (8) + service area (4) + category (2) + time window (1)
 *
 * Ties go to the latest effective_from, then the highest version, then the highest id, so resolution is fully
 * deterministic.
 */
final class RuleSetResolver
{
    /**
     * @param  list<ZoneRef>  $pickupZones
     */
    public function resolve(Market $market, RideCategory $category, array $pickupZones, CarbonInterface $at): ?PricingRuleSet
    {
        return $this->ranked($market, $category, $pickupZones, $at)->first();
    }

    /**
     * Every applicable candidate, best first (exposed for the admin simulator and tests).
     *
     * @param  list<ZoneRef>  $pickupZones
     * @return Collection<int, PricingRuleSet>
     */
    public function ranked(Market $market, RideCategory $category, array $pickupZones, CarbonInterface $at): Collection
    {
        $areaId = $market->serviceAreaId();
        $zoneSlugs = array_map(fn (ZoneRef $z) => $z->slug, $pickupZones);

        return PricingRuleSet::query()
            ->inForceAt($at)
            ->where('country', $market->country)
            ->where('currency', $market->currency)
            ->where(fn ($q) => $q->whereNull('service_area_id')->when($areaId !== null, fn ($q) => $q->orWhere('service_area_id', $areaId)))
            ->where(fn ($q) => $q->whereNull('ride_category_id')->orWhere('ride_category_id', $category->id))
            ->get()
            ->filter(fn (PricingRuleSet $r) => $r->zone_slug === null || in_array($r->zone_slug, $zoneSlugs, true))
            ->filter(fn (PricingRuleSet $r) => TimeWindows::contains($r->time_windows, $at, $r->timezone))
            ->sort(function (PricingRuleSet $a, PricingRuleSet $b) {
                return [self::specificity($b), $b->effective_from?->getTimestamp() ?? 0, $b->version, $b->id]
                    <=> [self::specificity($a), $a->effective_from?->getTimestamp() ?? 0, $a->version, $a->id];
            })
            ->values();
    }

    public static function specificity(PricingRuleSet $rules): int
    {
        return ($rules->zone_slug !== null ? 8 : 0)
            + ($rules->service_area_id !== null ? 4 : 0)
            + ($rules->ride_category_id !== null ? 2 : 0)
            + (! empty($rules->time_windows) ? 1 : 0);
    }
}
