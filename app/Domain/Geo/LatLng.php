<?php

namespace App\Domain\Geo;

use InvalidArgumentException;

/**
 * A WGS84 point. Latitude first everywhere in PHP; providers that want [lng, lat] get it at the adapter edge.
 */
final readonly class LatLng
{
    public function __construct(public float $lat, public float $lng)
    {
        if (! is_finite($lat) || $lat < -90 || $lat > 90) {
            throw new InvalidArgumentException('Latitude must be between -90 and 90.');
        }

        if (! is_finite($lng) || $lng < -180 || $lng > 180) {
            throw new InvalidArgumentException('Longitude must be between -180 and 180.');
        }
    }

    public static function from(float|int|string $lat, float|int|string $lng): self
    {
        return new self((float) $lat, (float) $lng);
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function toArray(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng];
    }

    public function rounded(int $decimals): self
    {
        return new self(round($this->lat, $decimals), round($this->lng, $decimals));
    }

    public function key(int $decimals = 5): string
    {
        return number_format($this->lat, $decimals, '.', '').','.number_format($this->lng, $decimals, '.', '');
    }

    public function distanceTo(self $other): float
    {
        return Geometry\Haversine::metres($this, $other);
    }
}
