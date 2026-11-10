<?php

namespace App\Http\Middleware\Passenger;

use App\Domain\Geo\Support\GeoCsp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content-Security-Policy for the passenger app and the public fare calculator (D-806). Stricter than the global
 * policy in SecurityHeaders (no CDN, no Google Fonts, no third-party images), plus the one external host these
 * pages need: the configured map tile server, from the geo domain's GeoCsp::imgSources(). SecurityHeaders leaves
 * a header the route already set alone, so this replaces the global policy on these routes only. It follows the
 * same report-only switch (security.csp_report_only) as the global policy.
 */
class PassengerContentPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $header = config('security.csp_report_only', true) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';

        if (! $response->headers->has($header)) {
            $response->headers->set($header, self::policy());
        }

        return $response;
    }

    public static function policy(): string
    {
        $tiles = implode(' ', GeoCsp::imgSources());

        return implode('; ', [
            "default-src 'self'",
            // The shared head runs one small inline script (theme before paint) with Vite's nonce.
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self'",
            trim("img-src 'self' data: blob: ".$tiles),
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            'report-uri /csp-report',
        ]);
    }
}
