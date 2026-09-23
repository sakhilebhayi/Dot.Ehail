<?php

namespace App\Services\Ehail;

/**
 * Populates Ride.estimated_fare / distance_km -- both columns have existed
 * since the original schema (wiki.md §3) but nothing has ever computed
 * them. Hand-rolled haversine distance rather than a mapping/routing API:
 * no new dependency, no API key, and it's the honest distance this app can
 * actually compute from the lat/lng it already collects (straight-line,
 * not road distance -- there's no routing engine here to do better).
 */
class FareCalculator
{
    private const BASE_FARE = 25.0;

    private const PER_KM_RATE = 8.5;

    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * Vehicle tiers multiply the metered portion of the fare, not the base
     * fare -- matches how real ride-hailing pricing tiers work (the
     * pickup fee is flat, the per-km rate is what scales with vehicle
     * class).
     */
    private const VEHICLE_TYPE_MULTIPLIERS = [
        'economy' => 1.0,
        'standard' => 1.2,
        'premium' => 1.6,
        'suv' => 1.8,
    ];

    /**
     * Great-circle distance between two coordinates in kilometers.
     */
    public function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round(self::EARTH_RADIUS_KM * $c, 2);
    }

    /**
     * Base fare plus a per-km rate scaled by vehicle tier. Distance-only
     * (no time-of-day/surge modeling -- no data source for that exists in
     * this app), applied to whatever distance is passed in, so the same
     * method serves both the pre-trip estimate (haversine) and, in the
     * future, a real trip distance if one is ever tracked.
     */
    public function estimate(float $distanceKm, string $vehicleType): float
    {
        $multiplier = self::VEHICLE_TYPE_MULTIPLIERS[$vehicleType] ?? 1.0;

        return round(self::BASE_FARE + ($distanceKm * self::PER_KM_RATE * $multiplier), 2);
    }
}
