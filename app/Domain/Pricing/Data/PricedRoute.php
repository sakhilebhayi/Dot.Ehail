<?php

namespace App\Domain\Pricing\Data;

/**
 * A ROAD route (never straight-line) from the geo routing seam: distance, duration and the polyline whose hash
 * binds a quote to the route it priced.
 */
final readonly class PricedRoute
{
    public function __construct(
        public int $distanceM,
        public int $durationS,
        public string $polyline,
        public string $provider,
    ) {}

    public function polylineHash(): string
    {
        return hash('sha256', $this->polyline);
    }
}
