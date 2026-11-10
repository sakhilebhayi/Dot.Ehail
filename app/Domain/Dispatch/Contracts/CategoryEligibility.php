<?php

namespace App\Domain\Dispatch\Contracts;

use App\Models\Vehicle;

/**
 * Seam to the pricing/catalog domain (ride_categories, category_vehicle_rules):
 * may this vehicle serve a ride requested in this category? The default
 * binding applies config/ehail.php vehicle_tiers, the rule that exists today.
 */
interface CategoryEligibility
{
    public function vehicleCanServe(Vehicle $vehicle, string $category): bool;
}
