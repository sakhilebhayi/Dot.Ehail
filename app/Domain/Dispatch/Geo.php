<?php

namespace App\Domain\Dispatch;

/**
 * Great-circle helpers for ranking only. Straight-line distance is never
 * billed (D-4); fares come from the pricing domain's road routes.
 */
final class Geo
{
    private const EARTH_RADIUS_M = 6371008.8;

    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return (int) round(2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a))));
    }

    /**
     * A lat/lng bounding box that contains the circle (prefilter for an index range scan).
     *
     * @return array{0: float, 1: float, 2: float, 3: float} minLat, maxLat, minLng, maxLng
     */
    public static function boundingBox(float $lat, float $lng, int $radiusM): array
    {
        $dLat = $radiusM / 111320.0;
        $dLng = $radiusM / (111320.0 * max(0.01, cos(deg2rad($lat))));

        return [$lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng];
    }
}
