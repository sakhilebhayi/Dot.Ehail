<?php

namespace App\Domain\Perf;

use App\Domain\Integration\Http\JsonErrorPolicy;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Http\Request;
use PDOException;
use Throwable;

/**
 * What people see while the database is unreachable (docs/R46_PERF.md section 3, D-1510). Before this, a lost or
 * refused connection was a generic 500 "Server Error". Now:
 *  - JSON clients (the apps' polls and actions): 503 {error: database_unavailable, message} with Retry-After: 5.
 *    The driver and passenger apps already treat a failed poll as "no connection, retrying" and keep their state.
 *  - Pages: the existing 503 page ("temporarily unavailable"), not the 500 page.
 * Nothing is half-written: transactions roll back with the connection. The exception is still reported (logged).
 */
class DatabaseOutage
{
    public const MESSAGE = 'Dot.Ehail cannot reach its database for a moment. Nothing you did is lost; this screen tries again by itself.';

    public static function is(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            $message = $cause->getMessage();

            if ((new LostConnectionDetector)->causedByLostConnection($cause)) {
                return true;
            }

            // Connection-level failures the detector does not list: SQLSTATE class 08 (connection exception),
            // SQLite's unopenable file, PostgreSQL refusing new connections.
            if ($cause instanceof PDOException && (str_starts_with((string) $cause->getCode(), '08')
                || str_contains($message, 'unable to open database file')
                || str_contains($message, 'is not currently accepting connections')
                || str_contains($message, 'Connection refused')
                || str_contains($message, 'could not connect to server'))) {
                return true;
            }
        }

        return false;
    }

    public static function render(Throwable $e, Request $request): mixed
    {
        if (! self::is($e)) {
            return null;
        }

        if (JsonErrorPolicy::wantsJson($request)) {
            return response()->json(['error' => 'database_unavailable', 'message' => self::MESSAGE], 503, ['Retry-After' => '5']);
        }

        return response()->view('errors.503', ['exception' => $e], 503, ['Retry-After' => '5']);
    }
}
