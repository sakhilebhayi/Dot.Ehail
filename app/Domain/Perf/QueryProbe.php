<?php

namespace App\Domain\Perf;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Development-only cost probe (docs/R46_PERF.md): counts every SQL statement a request runs (session, auth,
 * rate limiter and application queries alike) and how many of them write, and reports them in response
 * headers so the load scripts can attribute cost per endpoint on the real middleware stack.
 *
 * Registered only when APP_ENV is local or testing and EHAIL_PERF_PROBE=true (EhailPerfServiceProvider).
 */
class QueryProbe
{
    public static int $queries = 0;

    public static int $writes = 0;

    public static float $dbMs = 0.0;

    private static bool $listening = false;

    public function handle(Request $request, Closure $next): Response
    {
        self::listen();
        self::$queries = 0;
        self::$writes = 0;
        self::$dbMs = 0.0;
        $started = hrtime(true);

        $response = $next($request);

        $response->headers->set('X-Ehail-Queries', (string) self::$queries);
        $response->headers->set('X-Ehail-Writes', (string) self::$writes);
        $response->headers->set('X-Ehail-Db-Ms', number_format(self::$dbMs, 2, '.', ''));
        $response->headers->set('X-Ehail-Ms', number_format((hrtime(true) - $started) / 1e6, 2, '.', ''));

        return $response;
    }

    public static function listen(): void
    {
        if (self::$listening) {
            return;
        }

        self::$listening = true;

        DB::listen(function (QueryExecuted $query): void {
            self::$queries++;
            self::$dbMs += $query->time;

            if (self::isWrite($query->sql)) {
                self::$writes++;
            }
        });
    }

    public static function isWrite(string $sql): bool
    {
        return preg_match('/^\s*(insert|update|delete|replace|upsert)\b/i', $sql) === 1;
    }
}
