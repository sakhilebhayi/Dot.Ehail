<?php

namespace App\Domain\Dispatch\Seams;

use App\Domain\Dispatch\Contracts\CategoryEligibility;
use App\Models\Vehicle;

/**
 * Default CategoryEligibility: config/ehail.php vehicle_tiers (Vehicle::canServe).
 */
class ConfigTierEligibility implements CategoryEligibility
{
    public function vehicleCanServe(Vehicle $vehicle, string $category): bool
    {
        return $vehicle->canServe($category);
    }
}
