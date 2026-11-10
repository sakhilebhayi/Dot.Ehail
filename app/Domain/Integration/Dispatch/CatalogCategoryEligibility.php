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
    /**
     * A dispatch tick asks once per candidate (up to 20) about the same category: the category and its vehicle
     * rules are read once and reused for a few seconds (D-1511). Short enough that an operator's catalogue edit
     * applies within the TTL even in a long-running worker.
     *
     * @var array<string, array{0: RideCategory|null, 1: float}>
     */
    private array $categories = [];

    private const CATEGORY_TTL_SECONDS = 10;

    public function __construct(private readonly CatalogEligibility $catalog) {}

    public function vehicleCanServe(Vehicle $vehicle, string $category): bool
    {
        $model = $this->category($category);

        if ($model === null || ! $this->catalog->vehicleQualifies($model, $vehicle)) {
            return false;
        }

        // The dispatcher loads the candidates' profiles in one query and sets the relation; read it when present.
        $profile = $vehicle->relationLoaded('driverProfile') ? $vehicle->getRelation('driverProfile') : DriverProfile::withoutGlobalScope('user')->find($vehicle->driver_profile_id);

        return $profile !== null && $this->catalog->driverFailures($model, $profile) === [];
    }

    private function category(string $key): ?RideCategory
    {
        [$model, $at] = $this->categories[$key] ?? [null, 0.0];

        if ($at === 0.0 || microtime(true) - $at > self::CATEGORY_TTL_SECONDS) {
            $model = RideCategory::query()->with('vehicleRules')->where('key', $key)->first()
                ?? RideCategoryCatalogue::forLegacyVehicleType($key)?->load('vehicleRules');
            $this->categories[$key] = [$model, microtime(true)];
        }

        return $model;
    }
}
