<?php

namespace App\Domain\Pricing\Data;

use InvalidArgumentException;

/**
 * A WGS84 point as the pricing domain sees it. Mirrors App\Domain\Geo\LatLng (the bridge adapters convert), so
 * pricing compiles and tests without the geo domain merged.
 */
final readonly class GeoPoint
{
    public function __construct(public float $lat, public float $lng)
    {
        if (! is_finite($lat) || $lat < -90 || $lat > 90 || ! is_finite($lng) || $lng < -180 || $lng > 180) {
            throw new InvalidArgumentException('Coordinates are out of range.');
        }
    }

    public static function from(float|int|string $lat, float|int|string $lng): self
    {
        return new self((float) $lat, (float) $lng);
    }

    /**
     * Great-circle metres. Used ONLY to check that a booking matches its quote's points, never to bill.
     */
    public function metresTo(self $other): float
    {
        $radius = 6_371_000.0;
        $dLat = deg2rad($other->lat - $this->lat);
        $dLng = deg2rad($other->lng - $this->lng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($this->lat)) * cos(deg2rad($other->lat)) * sin($dLng / 2) ** 2;

        return $radius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Fixed 7-decimal text, the precision the quote columns store (used in the quote signature).
     */
    public function key(): string
    {
        return number_format($this->lat, 7, '.', '').','.number_format($this->lng, 7, '.', '');
    }
}
