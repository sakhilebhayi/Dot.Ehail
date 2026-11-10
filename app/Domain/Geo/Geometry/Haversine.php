<?php

namespace App\Domain\Geo\Geometry;

use App\Domain\Geo\LatLng;

/**
 * Great-circle distance on a sphere of the mean Earth radius. Accurate to about 0.5% against the WGS84 ellipsoid,
 * which is far below GPS noise. This is the "straight line" distance: informational only, never billed.
 */
final class Haversine
{
    public const EARTH_RADIUS_M = 6371008.8;

    public static function metres(LatLng $a, LatLng $b): float
    {
        $lat1 = deg2rad($a->lat);
        $lat2 = deg2rad($b->lat);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad($b->lng - $a->lng);

        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($h)));
    }

    /**
     * Initial bearing from a to b in degrees clockwise from north, 0..360.
     */
    public static function bearing(LatLng $a, LatLng $b): float
    {
        $lat1 = deg2rad($a->lat);
        $lat2 = deg2rad($b->lat);
        $dLng = deg2rad($b->lng - $a->lng);

        $y = sin($dLng) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLng);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /**
     * Sum of segment lengths along a path.
     *
     * @param  list<LatLng>  $points
     */
    public static function pathMetres(array $points): float
    {
        $total = 0.0;

        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $total += self::metres($points[$i - 1], $points[$i]);
        }

        return $total;
    }
}
