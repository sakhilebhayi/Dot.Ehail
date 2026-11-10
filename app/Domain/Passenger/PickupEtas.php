<?php

namespace App\Domain\Passenger;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Dispatch\Contracts\CategoryEligibility;
use App\Domain\Dispatch\Contracts\DriverLocator;
use App\Domain\Dispatch\Contracts\PickupEtaEstimator;
use App\Domain\Dispatch\Data\DriverPosition;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;

/**
 * "How far away is the nearest car of each kind?" for the compare cards (D-803). Read through dispatch's own
 * contracts (the same locator, eligibility and ETA estimator dispatch ranks drivers with), so the answer matches
 * who would actually be offered the ride. Returns minutes per category only: never a driver, a position or a count.
 * The method is passed through so a straight-line estimate is labelled as one (never presented as a road ETA).
 */
class PickupEtas
{
    private const RADIUS_M = 10_000;

    private const CANDIDATES = 25;

    public function __construct(
        private readonly DriverLocator $locator,
        private readonly CategoryEligibility $eligibility,
        private readonly PickupEtaEstimator $estimator,
    ) {}

    /**
     * @return array<string, array{state: string, eta_s?: int, method?: string}> category key => state 'eta' (nearest
     *                                                                           eligible driver's ETA), 'none' (no eligible driver within 10 km) or 'unknown' (the estimate failed)
     */
    public function forPickup(float $lat, float $lng, User $passenger): array
    {
        $categories = RideCategory::query()->activeRides()->pluck('key')->all();
        $positions = $this->locator->nearbyDrivers($lat, $lng, self::RADIUS_M, self::CANDIDATES, [$passenger->id]);
        $result = array_fill_keys($categories, ['state' => 'none']);

        if ($positions === []) {
            return $result;
        }

        $vehicles = Vehicle::query()->whereIn('id', array_filter(array_map(fn (DriverPosition $p) => $p->vehicleId, $positions)))
            ->where('is_active', true)->get()->keyBy('id');
        $approved = DriverProfile::withoutGlobalScope('user')
            ->whereIn('user_id', array_map(fn (DriverPosition $p) => $p->userId, $positions))
            ->where('status', 'approved')->pluck('id', 'user_id');
        $estimates = [];

        foreach ($categories as $category) {
            foreach ($positions as $position) {
                $vehicle = $position->vehicleId ? ($vehicles[$position->vehicleId] ?? null) : null;

                if ($vehicle === null || ! isset($approved[$position->userId]) || $vehicle->driver_profile_id !== $approved[$position->userId]) {
                    continue;
                }

                if (! $this->eligibility->vehicleCanServe($vehicle, $category)) {
                    continue;
                }

                // One estimate per driver, however many categories that car serves.
                $estimates[$position->userId] ??= $this->estimate($position, $lat, $lng);
                $result[$category] = $estimates[$position->userId];

                break;
            }
        }

        return $result;
    }

    /**
     * @return array{state: string, eta_s?: int, method?: string}
     */
    private function estimate(DriverPosition $position, float $lat, float $lng): ?array
    {
        try {
            $eta = $this->estimator->estimate($position->lat, $position->lng, $lat, $lng);
        } catch (\Throwable) {
            return ['state' => 'unknown'];
        }

        return ['state' => 'eta', 'eta_s' => $eta->seconds, 'method' => $eta->method];
    }
}
