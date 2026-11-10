<?php

namespace Tests\Doubles\Pricing;

use App\Domain\Pricing\Contracts\PricingRoutePlanner;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Data\PricedRoute;
use App\Domain\Pricing\Exceptions\RouteUnavailable;

/**
 * Test double for the geo routing seam: returns a fixed ROAD route (distance/duration you choose), or throws
 * RouteUnavailable. Records calls so tests can assert routing was (or was not) asked.
 */
final class FakeRoutePlanner implements PricingRoutePlanner
{
    /** @var list<array{GeoPoint, GeoPoint}> */
    public array $calls = [];

    public function __construct(
        public int $distanceM = 10_000,
        public int $durationS = 900,
        public string $polyline = 'fake_polyline_abc',
        public ?string $failWith = null,
    ) {}

    public static function failing(string $reason = 'not_configured'): self
    {
        return new self(failWith: $reason);
    }

    public function isConfigured(): bool
    {
        return $this->failWith !== 'not_configured';
    }

    public function route(GeoPoint $from, GeoPoint $to): PricedRoute
    {
        $this->calls[] = [$from, $to];

        if ($this->failWith !== null) {
            throw new RouteUnavailable($this->failWith);
        }

        return new PricedRoute($this->distanceM, $this->durationS, $this->polyline, 'fake');
    }
}
