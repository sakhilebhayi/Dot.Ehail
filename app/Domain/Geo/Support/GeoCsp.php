<?php

namespace App\Domain\Geo\Support;

/**
 * Seam for App\Http\Middleware\SecurityHeaders (not owned by the geo domain): the origins the map needs in the
 * Content-Security-Policy. Today the policy is report-only, so tiles load but every tile is reported; once it is
 * enforced, img-src must include these or the map goes blank. Derived from config so a provider switch needs no
 * code change.
 */
final class GeoCsp
{
    /**
     * Origins for img-src (raster tiles; Leaflet loads them as <img>).
     *
     * @return list<string>
     */
    public static function imgSources(): array
    {
        $url = (string) config('ehail-geo.tiles.url', '');
        $parts = parse_url(str_replace(['{s}', '{z}', '{x}', '{y}', '{r}'], ['a', '0', '0', '0', ''], $url));

        if (! isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https') {
            return [];
        }

        // A {s} subdomain template covers several hosts.
        $host = str_contains($url, '{s}.') ? '*.'.substr($parts['host'], strpos($parts['host'], '.') + 1) : $parts['host'];

        return ['https://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '')];
    }
}
