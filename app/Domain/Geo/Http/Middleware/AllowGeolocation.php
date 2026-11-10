<?php

namespace App\Domain\Geo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The global SecurityHeaders middleware sends Permissions-Policy geolocation=(), which blocks "use my location"
 * and driver position reporting outright. Pages that need the device position put this middleware on their route:
 * it marks the response, and SecurityHeaders composes the one policy with geolocation allowed for this origin only.
 */
class AllowGeolocation
{
    public const MARKER = 'X-Eh-Geolocation';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set(self::MARKER, 'self');

        return $response;
    }
}
