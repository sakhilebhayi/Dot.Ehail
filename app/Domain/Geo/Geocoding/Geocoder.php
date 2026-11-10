<?php

namespace App\Domain\Geo\Geocoding;

use App\Domain\Geo\Exceptions\GeocodingUnavailable;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Support\CircuitBreaker;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Search (country-biased, near-point-biased) and reverse geocoding through the configured driver, with a result
 * cache where the provider's terms allow it, an application-wide request ceiling where the provider sets one
 * (public Nominatim: 1 per second), and a circuit breaker.
 */
class Geocoder
{
    public function __construct(private GeocodingDriver $driver, private CircuitBreaker $breaker) {}

    public function isConfigured(): bool
    {
        return $this->driver->isConfigured();
    }

    public function supportsAutocomplete(): bool
    {
        return $this->driver->supportsAutocomplete();
    }

    public function providerName(): string
    {
        return $this->driver->name();
    }

    /**
     * @return list<Place>
     *
     * @throws GeocodingUnavailable
     */
    public function search(string $query, ?LatLng $near = null, ?string $country = null): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');
        $country ??= (string) config('ehail-geo.geocoding.country', 'ZA');
        $language = (string) config('ehail-geo.geocoding.language', 'en');
        $limit = (int) config('ehail-geo.geocoding.limit', 6);

        if (mb_strlen($query) < 3) {
            return [];
        }

        $key = 'search:'.hash('sha256', implode('|', [mb_strtolower($query), $near?->key(2) ?? '-', $country, $language]));

        /** @var list<array{label: string, name: string, lat: float, lng: float, provider: string, ref: ?string}> $rows */
        $rows = $this->remember($key, fn () => array_map(
            fn (Place $p) => $p->toArray(),
            $this->driver->search($query, $near, $country, $limit, $language),
        ));

        return array_map(fn (array $row) => Place::fromArray($row), $rows);
    }

    /**
     * @throws GeocodingUnavailable
     */
    public function reverse(LatLng $point): ?Place
    {
        $language = (string) config('ehail-geo.geocoding.language', 'en');
        // ~11 m: close enough to name the same street address.
        $key = 'reverse:'.$point->key(4).':'.$language;

        $row = $this->remember($key, fn () => $this->driver->reverse($point->rounded(5), $language)?->toArray());

        return $row === null ? null : Place::fromArray($row);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $fetch
     * @return T
     */
    private function remember(string $key, Closure $fetch): mixed
    {
        if (! $this->driver->isConfigured()) {
            throw new GeocodingUnavailable('not_configured', $this->driver->name());
        }

        $ttl = (int) (config('ehail-geo.geocoding.cache_ttl_seconds')[$this->driver->name()] ?? 0);
        $cacheKey = 'ehail-geo:geocode:'.$this->driver->name().':'.$key;

        if ($ttl > 0 && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $breakerName = 'geocoding:'.$this->driver->name();

        if ($this->breaker->isOpen($breakerName)) {
            throw new GeocodingUnavailable('circuit_open', $this->driver->name());
        }

        $this->respectProviderCeiling();

        try {
            $value = $fetch();
        } catch (GeocodingUnavailable $e) {
            if ($e->reason !== 'busy') {
                $this->breaker->recordFailure($breakerName);
                Log::warning('ehail.geo.geocoding_failed', ['provider' => $e->provider, 'reason' => $e->reason]);
            }

            throw $e;
        }

        $this->breaker->recordSuccess($breakerName);

        if ($ttl > 0) {
            Cache::put($cacheKey, $value, $ttl);
        }

        return $value;
    }

    /**
     * An application-wide ceiling for providers that set one (public Nominatim allows 1 request per second in
     * total). Over the ceiling the caller gets an honest "busy, try again" instead of the provider being hammered.
     */
    private function respectProviderCeiling(): void
    {
        $perSecond = (int) config('ehail-geo.providers.'.$this->driver->name().'.requests_per_second', 0);

        if ($perSecond <= 0) {
            return;
        }

        $limiterKey = 'ehail-geo:provider-ceiling:'.$this->driver->name();

        if (RateLimiter::tooManyAttempts($limiterKey, $perSecond)) {
            throw new GeocodingUnavailable('busy', $this->driver->name());
        }

        RateLimiter::hit($limiterKey, 1);
    }
}
