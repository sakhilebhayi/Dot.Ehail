<?php

namespace App\Http\Controllers\Ehail\Dispatch;

use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Services\Ehail\InvalidRideTransition;
use Closure;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON error shape for the dispatch API: {error: machine_code, message: text}.
 * DispatchRefused carries its own status; an invalid transition is 409
 * (the ride is not in a state that allows it, usually someone got there first).
 */
trait DispatchJson
{
    protected function attempt(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (DispatchRefused $e) {
            return $this->refusal($e);
        } catch (InvalidRideTransition $e) {
            return response()->json(['error' => 'invalid_transition', 'message' => $e->getMessage()], 409);
        } catch (QueryException $e) {
            // Lock contention that outlasted the transaction retries (SQLite "database is
            // locked", a deadlock victim, a serialisation failure): nothing was written.
            if (! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e)) {
                throw $e;
            }

            return response()->json(['error' => 'conflict_retry', 'message' => 'Someone else changed this ride at the same moment. Try again.'], 409)
                ->header('Retry-After', '1');
        }
    }

    protected function refusal(DispatchRefused $e): JsonResponse
    {
        $response = response()->json(['error' => $e->reason, 'message' => $e->getMessage()], $e->status);

        return $e->status === 409 && $e->reason === 'request_in_progress' ? $response->header('Retry-After', '1') : $response;
    }

    /**
     * 304 with the current ETag when the client is up to date, otherwise the
     * delta. Always private and uncached (personal data), with a polling hint.
     *
     * @param  array{version: int, body: array<string, mixed>|null}  $feed
     */
    protected function feedResponse(array $feed, int $retryAfter): Response
    {
        $headers = [
            'ETag' => '"v'.$feed['version'].'"',
            'Retry-After' => (string) $retryAfter,
            'Cache-Control' => 'private, no-store',
        ];

        if ($feed['body'] === null) {
            return response()->noContent(304)->withHeaders($headers);
        }

        return response()->json($feed['body'])->withHeaders($headers);
    }

    protected function sinceVersion(Request $request): int
    {
        $etag = trim((string) $request->header('If-None-Match'), " \t\"W/");

        if (preg_match('/^v(\d{1,19})$/', $etag, $m) === 1) {
            return (int) $m[1];
        }

        return max(0, (int) $request->query('since', 0));
    }
}
