<?php

namespace App\Domain\Integration\Pricing;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Catalog\Services\CategoryEligibility;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Locations\LocationStore;
use App\Domain\Geo\Locations\NearbyDriver;
use App\Domain\Pricing\Contracts\EligibleSupplyCounter;
use App\Domain\Pricing\Data\GeoPoint;
use App\Models\DriverProfile;
use App\Models\Vehicle;
use Carbon\CarbonInterface;

/**
 * "Are there drivers for this category near the pickup right now?" (D-710): drivers whose screened geo position
 * is fresh and within dispatch's widest search ring, who are online and free in dispatch's availability, and
 * whose active vehicle and profile qualify for the category in the catalogue. 0 makes pricing say
 * "no drivers available nearby"; null (no pickup point) means unknown.
 */
class DispatchSupplyCounter implements EligibleSupplyCounter
{
    public function __construct(private readonly LocationStore $locations, private readonly CategoryEligibility $eligibility) {}

    public function count(RideCategory $category, ?GeoPoint $near, ?int $serviceAreaId, CarbonInterface $at): ?int
    {
        if ($near === null) {
            return null;
        }

        $radius = max((array) config('ehail_dispatch.search_radii_m', [10_000]) ?: [10_000]);
        $stale = (int) config('ehail_dispatch.heartbeat_stale_seconds');
        $nearby = $this->locations->nearbyDrivers(new LatLng($near->lat, $near->lng), (float) $radius, ['max_age_seconds' => $stale]);

        if ($nearby === []) {
            return 0;
        }

        $rows = DriverAvailability::query()
            ->whereIn('user_id', array_map(fn (NearbyDriver $d) => $d->driverId, $nearby))
            ->where('state', DriverAvailability::ONLINE)
            ->whereNull('current_ride_id')
            ->where('last_heartbeat_at', '>=', $at->copy()->subSeconds($stale))
            ->whereNotNull('vehicle_id')
            ->get(['user_id', 'vehicle_id']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $category->loadMissing('vehicleRules');
        $vehicles = Vehicle::query()->whereIn('id', $rows->pluck('vehicle_id'))->where('is_active', true)->get()->keyBy('id');
        $profiles = DriverProfile::withoutGlobalScope('user')->whereIn('user_id', $rows->pluck('user_id'))->get()->keyBy('user_id');

        return $rows->filter(function (DriverAvailability $row) use ($vehicles, $profiles, $category, $at) {
            $vehicle = $vehicles->get($row->vehicle_id);
            $profile = $profiles->get($row->user_id);

            return $vehicle !== null && $profile !== null
                && $this->eligibility->vehicleQualifies($category, $vehicle, $at)
                && $this->eligibility->driverFailures($category, $profile) === [];
        })->count();
    }
}
