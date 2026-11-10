<?php

namespace App\Domain\Geo\Routing;

use App\Domain\Geo\Geometry\Polyline;
use App\Domain\Geo\LatLng;

/**
 * A road route from a routing provider: the "estimated route" distance in the distance vocabulary of
 * docs/R46_GEO.md. kind is always 'road'; nothing in this domain ever produces a straight-line RouteResult.
 */
final readonly class RouteResult
{
    public string $kind;

    public function __construct(
        public int $distanceM,
        public int $durationS,
        public string $polyline,
        public string $provider,
        public bool $fromCache = false,
    ) {
        $this->kind = 'road';
    }

    /**
     * @return list<LatLng>
     */
    public function points(): array
    {
        return $this->polyline === '' ? [] : Polyline::decode($this->polyline);
    }

    /**
     * @return array{distance_m: int, duration_s: int, polyline: string, provider: string, kind: string, from_cache: bool}
     */
    public function toArray(): array
    {
        return [
            'distance_m' => $this->distanceM,
            'duration_s' => $this->durationS,
            'polyline' => $this->polyline,
            'provider' => $this->provider,
            'kind' => $this->kind,
            'from_cache' => $this->fromCache,
        ];
    }
}
