<?php

namespace App\Domain\Geo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The global SecurityHeaders middleware sends Permissions-Policy geolocation=(), which blocks "use my location"
 * and driver position reporting outright. Pages that need the device position put this middleware on their route:
 * it sets the same policy with geolocation allowed for this origin only, and SecurityHeaders leaves a header the
 * route already set alone.
 */
class AllowGeolocation
{
    public const POLICY = 'camera=(), microphone=(), geolocation=(self), payment=(), usb=()';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Permissions-Policy', self::POLICY);

        return $response;
    }
}
