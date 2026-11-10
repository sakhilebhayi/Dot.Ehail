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
use Illuminate\Support\Collection;

/**
 * "Are there drivers for this category near the pickup right now?" (D-710): drivers whose screened geo position
 * is fresh and within dispatch's widest search ring, who are online and free in dispatch's availability, and
 * whose active vehicle and profile qualify for the category in the catalogue. 0 makes pricing say
 * "no drivers available nearby"; null (no pickup point) means unknown.
 */
class DispatchSupplyCounter implements EligibleSupplyCounter
{
    /**
     * Comparing categories asks once per category for the same pickup point: the free, fresh drivers around it
     * (positions, availability, vehicles, profiles) are read once per point and moment and filtered per category
     * (D-1512: was four queries and a full hydration of every nearby driver per category).
     *
     * @var array{key: string, rows: Collection, vehicles: Collection, profiles: Collection}|null
     */
    private ?array $supply = null;

    public function __construct(private readonly LocationStore $locations, private readonly CategoryEligibility $eligibility) {}

    public function count(RideCategory $category, ?GeoPoint $near, ?int $serviceAreaId, CarbonInterface $at): ?int
    {
        if ($near === null) {
            return null;
        }

        $supply = $this->supplyAround($near, $at);
        $category->loadMissing('vehicleRules');
        $count = 0;

        // Each shape group (same car shape, same driver standing) is checked once per category.
        foreach ($supply['groups'] as [$n, $vehicle, $profile]) {
            if ($this->eligibility->vehicleQualifies($category, $vehicle, $at) && $this->eligibility->driverFailures($category, $profile) === []) {
                $count += $n;
            }
        }

        return $count;
    }

    /**
     * The free, fresh drivers around a point, grouped by what the catalogue rules look at: the car's type, year,
     * seats, doors and features, and the driver's status, rating, trips and fleet. A city's 600 online drivers
     * collapse into a few dozen groups.
     *
     * @return array{key: string, groups: list<array{0: int, 1: Vehicle, 2: DriverProfile}>}
     */
    private function supplyAround(GeoPoint $near, CarbonInterface $at): array
    {
        $key = round($near->lat, 5).','.round($near->lng, 5).'@'.$at->getTimestamp();

        if ($this->supply !== null && $this->supply['key'] === $key) {
            return $this->supply;
        }

        $radius = max((array) config('ehail_dispatch.search_radii_m', [10_000]) ?: [10_000]);
        $stale = (int) config('ehail_dispatch.heartbeat_stale_seconds');
        $nearby = $this->locations->nearbyDrivers(new LatLng($near->lat, $near->lng), (float) $radius, ['max_age_seconds' => $stale]);
        $rows = $nearby === [] ? collect() : DriverAvailability::query()
            ->whereIn('user_id', array_map(fn (NearbyDriver $d) => $d->driverId, $nearby))
            ->where('state', DriverAvailability::ONLINE)
            ->whereNull('current_ride_id')
            ->where('last_heartbeat_at', '>=', $at->copy()->subSeconds($stale))
            ->whereNotNull('vehicle_id')
            ->get(['user_id', 'vehicle_id']);
        $groups = [];

        if ($rows->isNotEmpty()) {
            $vehicles = Vehicle::query()->whereIn('id', $rows->pluck('vehicle_id'))->where('is_active', true)->get()->keyBy('id');
            $profiles = DriverProfile::withoutGlobalScope('user')->whereIn('user_id', $rows->pluck('user_id'))->get()->keyBy('user_id');

            foreach ($rows as $row) {
                $vehicle = $vehicles->get($row->vehicle_id);
                $profile = $profiles->get($row->user_id);

                if ($vehicle === null || $profile === null) {
                    continue;
                }

                $shape = implode('|', [$vehicle->type, $vehicle->year, $vehicle->seats, json_encode($vehicle->getAttribute('doors')), json_encode($vehicle->getAttribute('features')),
                    $profile->status, $profile->rating, $profile->total_rides, $profile->fleet_id]);
                $groups[$shape] ??= [0, $vehicle, $profile];
                $groups[$shape][0]++;
            }
        }

        return $this->supply = ['key' => $key, 'groups' => array_values($groups)];
    }
}
