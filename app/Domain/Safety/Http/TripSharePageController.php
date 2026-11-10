<?php

namespace App\Domain\Safety\Http;

use App\Domain\Safety\TripShareService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Public trip-share page /t/{token} and its polling endpoint. Served without the session,
 * cookie and CSRF middleware (no cookies are set), noindex, no-store. An unknown, revoked or
 * expired token gets the same 404 page, so tokens cannot be probed for state.
 */
class TripSharePageController extends Controller
{
    public function __construct(private readonly TripShareService $shares) {}

    public function show(string $token): Response
    {
        $share = $this->shares->resolve($token);

        if ($share === null) {
            return $this->noStore(response()->view('safety.trip-share', ['payload' => null, 'statusUrl' => null, 'map' => null], 404));
        }

        $this->shares->recordView($share);

        return $this->noStore(response()->view('safety.trip-share', [
            'payload' => $this->shares->publicPayload($share),
            'statusUrl' => route('trip-share.status', $token),
            'map' => $this->shares->publicMap($share),
        ]));
    }

    public function status(string $token): JsonResponse
    {
        $share = $this->shares->resolve($token);

        if ($share === null) {
            return $this->noStore(response()->json(['state' => 'unavailable'], 404));
        }

        return $this->noStore(response()->json($this->shares->publicPayload($share)));
    }

    /**
     * @template T of \Symfony\Component\HttpFoundation\Response
     *
     * @param  T  $response
     * @return T
     */
    private function noStore($response)
    {
        $response->headers->set('Cache-Control', 'no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
