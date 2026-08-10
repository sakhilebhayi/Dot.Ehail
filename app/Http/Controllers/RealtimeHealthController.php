<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Redis;

/**
 * Real-time infrastructure health check.
 *
 * Laravel's built-in /up (bootstrap/app.php) only proves the app boots --
 * it says nothing about whether broadcasting is actually configured, the
 * queue driver broadcasts get dispatched through is reachable, or the
 * reverb:start process is even running. This walks each link in the chain
 * (App -> Broadcasting config -> Queue -> Reverb process) independently so
 * an on-call person can tell which one broke instead of just "something's
 * wrong somewhere". Channel authorization and frontend delivery aren't
 * checked here -- those need a real authenticated client, not a server-side
 * probe; BroadcastChannelAuthorizationTest covers authorization, and manual
 * browser verification covers the frontend listener/UI-update links.
 *
 * Same shape as Dot.Mines' RealtimeHealthController (the ecosystem
 * reference implementation, see docs/DOT_REALTIME_STANDARD.md there).
 */
class RealtimeHealthController extends Controller
{
    public function check(): JsonResponse
    {
        $checks = [
            'broadcasting_config' => $this->checkBroadcastingConfig(),
            'queue_connection' => $this->checkQueueConnection(),
            'reverb_process' => $this->checkReverbProcess(),
        ];

        $healthy = collect($checks)->every(fn (array $check) => $check['status'] === 'ok');

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function checkBroadcastingConfig(): array
    {
        $driver = config('broadcasting.default');

        if ($driver !== 'reverb') {
            return ['status' => 'fail', 'detail' => "BROADCAST_CONNECTION is \"{$driver}\", expected \"reverb\"."];
        }

        $missing = collect(['key', 'secret', 'app_id'])
            ->filter(fn (string $field) => blank(config("broadcasting.connections.reverb.{$field}")));

        if ($missing->isNotEmpty()) {
            return ['status' => 'fail', 'detail' => 'Missing Reverb credentials: '.$missing->implode(', ').'.'];
        }

        return ['status' => 'ok', 'detail' => 'Broadcasting configured for reverb with credentials present.'];
    }

    private function checkQueueConnection(): array
    {
        $connection = config('queue.default');

        if ($connection !== 'redis') {
            return ['status' => 'ok', 'detail' => "Queue connection \"{$connection}\" (not Redis-backed, nothing further to probe here)."];
        }

        try {
            Redis::connection(config('queue.connections.redis.connection', 'default'))->ping();

            return ['status' => 'ok', 'detail' => 'Redis queue connection reachable.'];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'detail' => 'Redis queue connection unreachable: '.$e->getMessage()];
        }
    }

    private function checkReverbProcess(): array
    {
        // The internal bind address (loopback-only in production, see
        // .env.production.example), not the public REVERB_HOST/PORT --
        // those go through a reverse proxy this check should bypass.
        // "0.0.0.0" is a valid bind address but not a connectable one --
        // dial the loopback interface instead when that's what's configured.
        $host = config('reverb.servers.reverb.host') ?: '127.0.0.1';
        $host = $host === '0.0.0.0' ? '127.0.0.1' : $host;
        $port = config('reverb.servers.reverb.port') ?: 8080;

        $connection = @fsockopen($host, (int) $port, $errno, $errstr, 1.0);

        if ($connection === false) {
            return [
                'status' => 'fail',
                'detail' => "Nothing listening on {$host}:{$port} ({$errstr}). Is reverb:start running?",
            ];
        }

        fclose($connection);

        return ['status' => 'ok', 'detail' => "reverb:start is accepting connections on {$host}:{$port}."];
    }
}
