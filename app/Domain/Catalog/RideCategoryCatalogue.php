<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\CategoryVehicleRule;
use App\Domain\Catalog\Models\RideCategory;
use Illuminate\Support\Facades\DB;

/**
 * The launch catalogue of ride categories (reference data, not prices). install() only inserts categories that do
 * not exist yet, so an operator's later edits (names, order, active flags, rules) are never overwritten.
 *
 * Required document keys use the existing driver_documents types (license, id, vehicle_inspection); the
 * verification domain decides which documents gate going online.
 */
final class RideCategoryCatalogue
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $baseDocs = ['license', 'id', 'vehicle_inspection'];
        $standardPolicy = ['cancellation_free_minutes' => 2, 'max_wait_minutes' => 5, 'pin_required' => true];

        return [
            [
                'key' => 'economy', 'name' => 'Economy', 'description' => 'Everyday rides in a compact car at the lowest price.',
                'capacity' => 4, 'luggage' => 2, 'driver_rating_floor' => null, 'required_documents' => $baseDocs,
                'policies' => $standardPolicy, 'legacy_vehicle_type' => 'economy', 'is_active' => true, 'display_order' => 10, 'icon_key' => 'car',
                'rules' => [['vehicle_types' => ['economy', 'standard'], 'max_age_years' => 12]],
            ],
            [
                'key' => 'standard', 'name' => 'Comfort', 'description' => 'Newer, roomier cars and highly rated drivers.',
                'capacity' => 4, 'luggage' => 3, 'driver_rating_floor' => 450, 'required_documents' => $baseDocs,
                'policies' => $standardPolicy, 'legacy_vehicle_type' => 'standard', 'is_active' => true, 'display_order' => 20, 'icon_key' => 'car-comfort',
                'rules' => [['vehicle_types' => ['standard', 'premium'], 'max_age_years' => 8]],
            ],
            [
                'key' => 'premium', 'name' => 'Premium', 'description' => 'Executive sedans with top-rated drivers.',
                'capacity' => 4, 'luggage' => 3, 'driver_rating_floor' => 470, 'required_documents' => $baseDocs,
                'policies' => ['cancellation_free_minutes' => 3, 'max_wait_minutes' => 7, 'pin_required' => true], 'legacy_vehicle_type' => 'premium',
                'is_active' => true, 'display_order' => 30, 'icon_key' => 'car-premium',
                'rules' => [['vehicle_types' => ['premium'], 'max_age_years' => 5]],
            ],
            [
                'key' => 'xl', 'name' => 'XL', 'description' => 'Up to six passengers in an SUV or people carrier.',
                'capacity' => 6, 'luggage' => 4, 'driver_rating_floor' => null, 'required_documents' => $baseDocs,
                'policies' => $standardPolicy, 'legacy_vehicle_type' => 'suv', 'is_active' => true, 'display_order' => 40, 'icon_key' => 'car-xl',
                'rules' => [['vehicle_types' => ['suv'], 'min_seats' => 6, 'max_age_years' => 10]],
            ],
            [
                'key' => 'accessible', 'name' => 'Accessible', 'description' => 'Wheelchair-accessible vehicles with a ramp or lift.',
                'capacity' => 4, 'luggage' => 2, 'driver_rating_floor' => null, 'required_documents' => $baseDocs,
                'policies' => ['cancellation_free_minutes' => 5, 'max_wait_minutes' => 10, 'pin_required' => true], 'legacy_vehicle_type' => null,
                'is_active' => true, 'display_order' => 50, 'icon_key' => 'accessible',
                'rules' => [['vehicle_types' => ['economy', 'standard', 'premium', 'suv'], 'required_features' => ['wheelchair_access']]],
            ],
            [
                // Inactive until business accounts exist (wave 2): it would otherwise be Premium under another name.
                'key' => 'business', 'name' => 'Business', 'description' => 'Premium rides billed to a business account.',
                'capacity' => 4, 'luggage' => 3, 'driver_rating_floor' => 470, 'required_documents' => $baseDocs,
                'policies' => ['cancellation_free_minutes' => 3, 'max_wait_minutes' => 7, 'pin_required' => true], 'legacy_vehicle_type' => null,
                'is_active' => false, 'display_order' => 60, 'icon_key' => 'briefcase',
                'rules' => [['vehicle_types' => ['premium'], 'max_age_years' => 5]],
            ],
            [
                'key' => 'airport', 'name' => 'Airport transfer', 'description' => 'To or from the airport with room for luggage.',
                'capacity' => 4, 'luggage' => 4, 'driver_rating_floor' => 450, 'required_documents' => $baseDocs,
                'policies' => ['cancellation_free_minutes' => 5, 'max_wait_minutes' => 15, 'pin_required' => true], 'legacy_vehicle_type' => null,
                'is_active' => true, 'display_order' => 70, 'icon_key' => 'plane',
                'rules' => [['vehicle_types' => ['standard', 'premium', 'suv'], 'max_age_years' => 8]],
            ],
            [
                'key' => 'long_distance', 'name' => 'Long distance', 'description' => 'Intercity trips with drivers who opt in.',
                'capacity' => 4, 'luggage' => 3, 'driver_rating_floor' => 460, 'required_documents' => $baseDocs,
                'policies' => ['cancellation_free_minutes' => 10, 'max_wait_minutes' => 10, 'pin_required' => true], 'legacy_vehicle_type' => null,
                'is_active' => true, 'display_order' => 80, 'icon_key' => 'road',
                'rules' => [['vehicle_types' => ['standard', 'premium', 'suv'], 'max_age_years' => 6]],
            ],
            [
                // Present so the catalogue is complete; courier is a separate service and stays inactive.
                'key' => 'parcel', 'name' => 'Parcel', 'description' => 'Courier deliveries. Not available yet.',
                'service_kind' => 'parcel', 'capacity' => 0, 'luggage' => 0, 'driver_rating_floor' => null, 'required_documents' => $baseDocs,
                'policies' => null, 'legacy_vehicle_type' => null, 'is_active' => false, 'display_order' => 90, 'icon_key' => 'parcel',
                'rules' => [['vehicle_types' => ['economy', 'standard']]],
            ],
        ];
    }

    /**
     * Insert missing categories (and their vehicle rules). Returns how many were created.
     */
    public static function install(): int
    {
        $created = 0;

        DB::transaction(function () use (&$created) {
            foreach (self::definitions() as $definition) {
                if (RideCategory::query()->where('key', $definition['key'])->exists()) {
                    continue;
                }

                $rules = $definition['rules'];
                unset($definition['rules']);

                $category = RideCategory::query()->create($definition + ['service_kind' => 'ride']);

                foreach ($rules as $rule) {
                    CategoryVehicleRule::query()->create($rule + ['ride_category_id' => $category->id]);
                }

                $created++;
            }
        });

        return $created;
    }

    /**
     * The category continuing an old rides.vehicle_type value (economy, standard, premium, suv).
     */
    public static function forLegacyVehicleType(string $vehicleType): ?RideCategory
    {
        return RideCategory::query()->where('legacy_vehicle_type', $vehicleType)->first()
            ?? RideCategory::query()->where('key', $vehicleType)->first();
    }
}
