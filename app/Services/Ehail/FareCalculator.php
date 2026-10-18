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
    private const EARTH_RADIUS_KM = 6371.0;

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
     * Base fare plus a per-km rate scaled by vehicle tier, all from
     * config/ehail.php (tariff.base_fare, tariff.per_km, tariff.multipliers).
     * The tier multiplies the metered portion only -- the pickup fee is flat.
     * Distance-only (no time-of-day/surge modeling -- no data source for that
     * exists in this app). An unknown tier uses a neutral multiplier.
     */
    public function estimate(float $distanceKm, string $vehicleType): float
    {
        $multiplier = (float) (config('ehail.tariff.multipliers')[$vehicleType] ?? 1.0);

        return round(
            (float) config('ehail.tariff.base_fare') + ($distanceKm * (float) config('ehail.tariff.per_km') * $multiplier),
            2,
        );
    }

    /**
     * "R 1,234.50" -- the configured currency symbol, display only.
     */
    public static function format(float|int|string $amount): string
    {
        return config('ehail.currency.symbol').' '.number_format((float) $amount, 2);
    }
}
