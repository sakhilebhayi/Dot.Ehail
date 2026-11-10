<?php

namespace App\Domain\Geo\Support;

use Illuminate\Contracts\Cache\Repository;

/**
 * Per-provider breaker kept in the application cache (shared by every PHP worker; no daemon needed). After
 * `failures` failures inside `window_seconds` the provider is considered open for `cooldown_seconds`, and callers
 * fail fast with an honest "temporarily unavailable" instead of stacking slow timeouts on every request.
 */
class CircuitBreaker
{
    public function __construct(private Repository $cache) {}

    public function isOpen(string $name): bool
    {
        return (bool) $this->cache->get($this->openKey($name), false);
    }

    public function recordFailure(string $name): void
    {
        $config = config('ehail-geo.circuit_breaker');
        $countKey = $this->countKey($name);

        $this->cache->add($countKey, 0, (int) $config['window_seconds']);
        $failures = (int) $this->cache->increment($countKey);

        if ($failures >= (int) $config['failures']) {
            $this->cache->put($this->openKey($name), true, (int) $config['cooldown_seconds']);
            $this->cache->forget($countKey);
        }
    }

    public function recordSuccess(string $name): void
    {
        $this->cache->forget($this->countKey($name));
    }

    private function countKey(string $name): string
    {
        return 'ehail-geo:breaker:'.$name.':failures';
    }

    private function openKey(string $name): string
    {
        return 'ehail-geo:breaker:'.$name.':open';
    }
}
