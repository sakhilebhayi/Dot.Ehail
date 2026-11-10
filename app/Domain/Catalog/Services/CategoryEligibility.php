<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\RideCategory;
use App\Models\DriverProfile;
use App\Models\Vehicle;
use Carbon\CarbonInterface;

/**
 * Whether a driver and vehicle may serve a category: the vehicle must satisfy at least one of the category's
 * vehicle rules; the driver must meet the rating floor and, for fleet-only categories, belong to a fleet.
 * Document verification is NOT decided here: the verification domain gates going online (architecture section 3).
 * Dispatch calls this when matching offers.
 */
final class CategoryEligibility
{
    /**
     * @return list<string> reasons the vehicle fails (empty = qualifies)
     */
    public function vehicleFailures(RideCategory $category, Vehicle $vehicle, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $rules = $category->vehicleRules;

        if ($rules->isEmpty()) {
            return ['no_vehicle_rules'];
        }

        $best = null;

        foreach ($rules as $rule) {
            $failures = $rule->failuresFor($vehicle, $at);

            if ($failures === []) {
                return [];
            }

            $best = $best === null || count($failures) < count($best) ? $failures : $best;
        }

        return $best ?? ['no_vehicle_rules'];
    }

    public function vehicleQualifies(RideCategory $category, Vehicle $vehicle, ?CarbonInterface $at = null): bool
    {
        return $this->vehicleFailures($category, $vehicle, $at) === [];
    }

    /**
     * @return list<string> reasons the driver fails (empty = qualifies)
     */
    public function driverFailures(RideCategory $category, DriverProfile $driver): array
    {
        $failures = [];

        if ($driver->status !== 'approved') {
            $failures[] = 'driver_not_approved';
        }

        if ($category->driver_rating_floor !== null) {
            $hundredths = (int) round(((float) $driver->rating) * 100);
            // A new driver with no ratings yet (0.00) is not held to the floor until rated; dispatch may tighten.
            $rated = (int) $driver->total_rides > 0 && $hundredths > 0;

            if ($rated && $hundredths < $category->driver_rating_floor) {
                $failures[] = 'rating_below_floor';
            }
        }

        if ($category->fleet_only && $driver->fleet_id === null) {
            $failures[] = 'fleet_only';
        }

        return $failures;
    }

    /**
     * Categories (active rides only) this vehicle can serve, by key.
     *
     * @return list<string>
     */
    public function servableCategoryKeys(Vehicle $vehicle, ?CarbonInterface $at = null): array
    {
        return RideCategory::query()->activeRides()->with('vehicleRules')->get()
            ->filter(fn (RideCategory $c) => $this->vehicleQualifies($c, $vehicle, $at))
            ->pluck('key')->values()->all();
    }
}
