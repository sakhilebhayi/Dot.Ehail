<?php

namespace App\Domain\Integration\Dispatch;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Catalog\RideCategoryCatalogue;
use App\Domain\Catalog\Services\CategoryEligibility as CatalogEligibility;
use App\Domain\Dispatch\Contracts\CategoryEligibility;
use App\Models\DriverProfile;
use App\Models\Vehicle;

/**
 * Dispatch's CategoryEligibility over the ride-category catalogue (category_vehicle_rules: vehicle class,
 * age, seats, doors, features; plus the driver rules: approval, rating floor, fleet-only). A ride made before
 * R-46 carries an old vehicle_type, which maps to its continuing category. An unknown category serves nobody.
 */
class CatalogCategoryEligibility implements CategoryEligibility
{
    public function __construct(private readonly CatalogEligibility $catalog) {}

    public function vehicleCanServe(Vehicle $vehicle, string $category): bool
    {
        $model = RideCategory::query()->with('vehicleRules')->where('key', $category)->first()
            ?? RideCategoryCatalogue::forLegacyVehicleType($category)?->load('vehicleRules');

        if ($model === null || ! $this->catalog->vehicleQualifies($model, $vehicle)) {
            return false;
        }

        $profile = DriverProfile::withoutGlobalScope('user')->find($vehicle->driver_profile_id);

        return $profile !== null && $this->catalog->driverFailures($model, $profile) === [];
    }
}
