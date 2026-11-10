<?php

namespace App\Domain\Perf;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Polling answers are small (a 304, or a few hundred bytes of JSON) but every one of them carried about 2 KB of
 * headers: a fresh XSRF-TOKEN cookie, a re-sent session cookie and the report-only Content-Security-Policy. On a
 * phone polling every 3 s that is most of the data a driver pays for (docs/R46_PERF.md section 4, D-1506).
 *
 * On the polling routes only, this removes:
 *  - the XSRF-TOKEN cookie (the apps send the page's csrf-token meta value; the cookie set by the page load stays);
 *  - the session cookie when the session was not written this request (LazyTouchDatabaseSessionHandler skipped
 *    it). The handler still writes, and the cookie is still re-sent, at least once a minute, so the cookie's
 *    expiry keeps sliding while the app is open;
 *  - the CSP header on JSON and 304 answers (a policy governs documents; it has no effect on a JSON body).
 * Every other route and every other header is untouched.
 */
class LeanPollResponses
{
    /** @var list<string> */
    public const ROUTES = [
        'ehail.dispatch.driver.feed',
        'ehail.dispatch.driver.sync',
        'ehail.dispatch.driver.heartbeat',
        'ehail.dispatch.rides.feed',
        'geo.driver.location',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $name = $request->route()?->getName();

        if ($name === null || ! in_array($name, self::ROUTES, true)) {
            return $response;
        }

        $sessionCookie = (string) config('session.cookie');

        foreach ($response->headers->getCookies() as $cookie) {
            $drop = $cookie->getName() === 'XSRF-TOKEN'
                || ($cookie->getName() === $sessionCookie && LazyTouchDatabaseSessionHandler::$skippedWrite && $request->cookies->has($sessionCookie));

            if ($drop) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }

        if ($response->getStatusCode() === 304 || str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            $response->headers->remove('Content-Security-Policy-Report-Only');
            $response->headers->remove('Content-Security-Policy');
        }

        return $response;
    }
}
