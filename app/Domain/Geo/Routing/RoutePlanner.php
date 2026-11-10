<?php

namespace App\Domain\Geo\Routing;

use App\Domain\Geo\Exceptions\RoutingUnavailable;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Models\RouteCacheEntry;
use App\Domain\Geo\Support\CircuitBreaker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * The single way to get a road route. Wraps the configured RoutingDriver with a database-backed response cache
 * (coordinates rounded to ~11 m, per provider and profile, with a TTL), a circuit breaker, and the honest
 * RoutingUnavailable failure every caller must handle. It never returns a straight-line estimate.
 */
class RoutePlanner
{
    public function __construct(private RoutingDriver $driver, private CircuitBreaker $breaker) {}

    public function isConfigured(): bool
    {
        return $this->driver->isConfigured();
    }

    public function providerName(): string
    {
        return $this->driver->name();
    }

    /**
     * @throws RoutingUnavailable
     */
    public function route(LatLng $from, LatLng $to, ?RouteOptions $options = null): RouteResult
    {
        $options ??= new RouteOptions;

        if (! $this->driver->isConfigured()) {
            throw RoutingUnavailable::notConfigured();
        }

        $breakerName = 'routing:'.$this->driver->name();
        $key = $this->cacheKey($from, $to, $options);

        if ($options->useCache && ($cached = $this->cached($key)) !== null) {
            return $cached;
        }

        if ($this->breaker->isOpen($breakerName)) {
            throw new RoutingUnavailable('circuit_open', $this->driver->name());
        }

        try {
            $result = $this->driver->route($from, $to, $options);
        } catch (RoutingUnavailable $e) {
            if ($e->reason !== 'no_route') {
                $this->breaker->recordFailure($breakerName);
                Log::warning('ehail.geo.routing_failed', ['provider' => $e->provider, 'reason' => $e->reason]);
            }

            throw $e;
        }

        $this->breaker->recordSuccess($breakerName);

        if ($options->useCache) {
            $this->store($key, $options, $result);
        }

        return $result;
    }

    private function cacheKey(LatLng $from, LatLng $to, RouteOptions $options): string
    {
        $precision = (int) config('ehail-geo.routing.cache_precision', 4);

        return hash('sha256', implode('|', [
            $this->driver->name(),
            $options->profile,
            $options->avoidTolls ? 'notolls' : 'tolls',
            $from->key($precision),
            $to->key($precision),
        ]));
    }

    private function cached(string $key): ?RouteResult
    {
        $entry = RouteCacheEntry::query()->where('cache_key', $key)->where('expires_at', '>', now())->first();

        return $entry === null ? null : new RouteResult($entry->distance_m, $entry->duration_s, $entry->polyline, $entry->provider, true);
    }

    private function store(string $key, RouteOptions $options, RouteResult $result): void
    {
        $ttl = (int) config('ehail-geo.routing.cache_ttl_seconds', 600);

        if ($ttl <= 0) {
            return;
        }

        try {
            RouteCacheEntry::query()->updateOrCreate(['cache_key' => $key], [
                'provider' => $result->provider,
                'profile' => $options->profile,
                'distance_m' => $result->distanceM,
                'duration_s' => $result->durationS,
                'polyline' => $result->polyline,
                'expires_at' => now()->addSeconds($ttl),
            ]);
        } catch (QueryException) {
            // Two requests racing to store the same key: the other one won, which is fine.
        }
    }
}
