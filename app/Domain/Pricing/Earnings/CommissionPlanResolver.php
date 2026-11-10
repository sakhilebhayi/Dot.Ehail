<?php

namespace App\Domain\Pricing\Earnings;

use App\Domain\Pricing\Models\CommissionPlan;
use Carbon\CarbonInterface;

/**
 * The commission plan for an arrangement: in force at the moment, same country and currency, and fitting fleet,
 * area and category (null fits all). Most specific wins: fleet (8) + area (4) + category (2); ties by latest
 * effective_from, highest version, highest id.
 */
final class CommissionPlanResolver
{
    public function resolve(DriverArrangement $arrangement, CarbonInterface $at): ?CommissionPlan
    {
        return CommissionPlan::query()
            ->inForceAt($at)
            ->where('country', $arrangement->country)
            ->where('currency', $arrangement->currency)
            ->where(fn ($q) => $q->whereNull('fleet_id')->when($arrangement->fleetId !== null, fn ($q) => $q->orWhere('fleet_id', $arrangement->fleetId)))
            ->where(fn ($q) => $q->whereNull('service_area_id')->when($arrangement->serviceAreaId !== null, fn ($q) => $q->orWhere('service_area_id', $arrangement->serviceAreaId)))
            ->where(fn ($q) => $q->whereNull('ride_category_id')->when($arrangement->categoryId !== null, fn ($q) => $q->orWhere('ride_category_id', $arrangement->categoryId)))
            ->get()
            ->sort(fn (CommissionPlan $a, CommissionPlan $b) => [self::specificity($b), $b->effective_from?->getTimestamp() ?? 0, $b->version, $b->id] <=> [self::specificity($a), $a->effective_from?->getTimestamp() ?? 0, $a->version, $a->id])
            ->first();
    }

    public static function specificity(CommissionPlan $plan): int
    {
        return ($plan->fleet_id !== null ? 8 : 0) + ($plan->service_area_id !== null ? 4 : 0) + ($plan->ride_category_id !== null ? 2 : 0);
    }
}
