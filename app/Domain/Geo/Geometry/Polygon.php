<?php

namespace App\Domain\Geo\Geometry;

use App\Domain\Geo\LatLng;
use InvalidArgumentException;

/**
 * A simple polygon ring (lat/lng vertices, closing edge implied). Containment is ray casting on the plane, which
 * is exact enough for city and airport sized shapes away from the poles and the antimeridian.
 */
final readonly class Polygon
{
    /** @var list<LatLng> */
    public array $vertices;

    public BoundingBox $bbox;

    /**
     * @param  list<LatLng>  $vertices
     */
    public function __construct(array $vertices)
    {
        // A closed ring (first == last) is accepted and normalised to the open form.
        if (count($vertices) > 1) {
            $first = $vertices[0];
            $last = $vertices[count($vertices) - 1];

            if ($first->lat === $last->lat && $first->lng === $last->lng) {
                array_pop($vertices);
            }
        }

        if (count($vertices) < 3) {
            throw new InvalidArgumentException('A polygon needs at least three distinct vertices.');
        }

        $this->vertices = array_values($vertices);
        $this->bbox = BoundingBox::around($this->vertices);
    }

    /**
     * @param  list<array{0: float|int|string, 1: float|int|string}>  $pairs  [[lat, lng], ...]
     */
    public static function fromPairs(array $pairs): self
    {
        return new self(array_map(function ($pair): LatLng {
            if (! is_array($pair) || count($pair) < 2) {
                throw new InvalidArgumentException('Each polygon vertex must be a [lat, lng] pair.');
            }

            return LatLng::from($pair[0], $pair[1]);
        }, array_values($pairs)));
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    public function toPairs(): array
    {
        return array_map(fn (LatLng $p) => [$p->lat, $p->lng], $this->vertices);
    }

    /**
     * Points exactly on an edge count as inside (an airport kerb on the boundary is still the airport).
     */
    public function contains(LatLng $point): bool
    {
        if (! $this->bbox->contains($point)) {
            return false;
        }

        $inside = false;
        $n = count($this->vertices);
        $x = $point->lng;
        $y = $point->lat;

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $this->vertices[$i]->lng;
            $yi = $this->vertices[$i]->lat;
            $xj = $this->vertices[$j]->lng;
            $yj = $this->vertices[$j]->lat;

            if (self::onSegment($x, $y, $xi, $yi, $xj, $yj)) {
                return true;
            }

            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private static function onSegment(float $x, float $y, float $x1, float $y1, float $x2, float $y2): bool
    {
        $cross = ($x - $x1) * ($y2 - $y1) - ($y - $y1) * ($x2 - $x1);

        if (abs($cross) > 1e-12) {
            return false;
        }

        return $x >= min($x1, $x2) - 1e-12 && $x <= max($x1, $x2) + 1e-12
            && $y >= min($y1, $y2) - 1e-12 && $y <= max($y1, $y2) + 1e-12;
    }
}
