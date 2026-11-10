<?php

namespace App\Domain\Geo\Geometry;

use App\Domain\Geo\LatLng;
use InvalidArgumentException;

/**
 * Axis-aligned latitude/longitude box, the cheap prefilter before exact tests. Does not wrap the antimeridian:
 * every market Dot.Ehail plans for is far from it, and a box that would cross it is refused.
 */
final readonly class BoundingBox
{
    public function __construct(
        public float $minLat,
        public float $minLng,
        public float $maxLat,
        public float $maxLng,
    ) {
        if ($minLat > $maxLat || $minLng > $maxLng) {
            throw new InvalidArgumentException('Bounding box minimums must not exceed maximums.');
        }
    }

    /**
     * @param  list<LatLng>  $points
     */
    public static function around(array $points): self
    {
        if ($points === []) {
            throw new InvalidArgumentException('A bounding box needs at least one point.');
        }

        $lats = array_map(fn (LatLng $p) => $p->lat, $points);
        $lngs = array_map(fn (LatLng $p) => $p->lng, $points);

        return new self(min($lats), min($lngs), max($lats), max($lngs));
    }

    /**
     * The smallest box containing every point within $radiusMetres of $centre (slightly generous, never short).
     */
    public static function radius(LatLng $centre, float $radiusMetres): self
    {
        $latDelta = rad2deg($radiusMetres / Haversine::EARTH_RADIUS_M);
        $cosLat = max(0.01, cos(deg2rad($centre->lat)));
        $lngDelta = rad2deg($radiusMetres / (Haversine::EARTH_RADIUS_M * $cosLat));

        return new self(
            max(-90.0, $centre->lat - $latDelta),
            max(-180.0, $centre->lng - $lngDelta),
            min(90.0, $centre->lat + $latDelta),
            min(180.0, $centre->lng + $lngDelta),
        );
    }

    public function contains(LatLng $point): bool
    {
        return $point->lat >= $this->minLat && $point->lat <= $this->maxLat
            && $point->lng >= $this->minLng && $point->lng <= $this->maxLng;
    }

    /**
     * @return array{min_lat: float, min_lng: float, max_lat: float, max_lng: float}
     */
    public function toArray(): array
    {
        return ['min_lat' => $this->minLat, 'min_lng' => $this->minLng, 'max_lat' => $this->maxLat, 'max_lng' => $this->maxLng];
    }
}
