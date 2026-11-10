<?php

namespace App\Domain\Geo\Geometry;

use App\Domain\Geo\LatLng;

/**
 * Shortest distance from a point to a path (a decoded route polyline). Each segment is projected onto a local
 * equirectangular plane centred on the point, which is accurate to well under a metre at the distances that
 * matter for route deviation (hundreds of metres).
 */
final class PathDistance
{
    /**
     * @param  list<LatLng>  $path
     */
    public static function metres(LatLng $point, array $path): float
    {
        $count = count($path);

        if ($count === 0) {
            return INF;
        }

        if ($count === 1) {
            return Haversine::metres($point, $path[0]);
        }

        $cosLat = cos(deg2rad($point->lat));
        $mPerDegLat = M_PI * Haversine::EARTH_RADIUS_M / 180;
        $mPerDegLng = $mPerDegLat * $cosLat;
        $best = INF;

        for ($i = 1; $i < $count; $i++) {
            $ax = ($path[$i - 1]->lng - $point->lng) * $mPerDegLng;
            $ay = ($path[$i - 1]->lat - $point->lat) * $mPerDegLat;
            $bx = ($path[$i]->lng - $point->lng) * $mPerDegLng;
            $by = ($path[$i]->lat - $point->lat) * $mPerDegLat;

            $dx = $bx - $ax;
            $dy = $by - $ay;
            $lengthSquared = $dx * $dx + $dy * $dy;
            $t = $lengthSquared > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $lengthSquared)) : 0.0;

            $px = $ax + $t * $dx;
            $py = $ay + $t * $dy;
            $best = min($best, sqrt($px * $px + $py * $py));
        }

        return $best;
    }
}
