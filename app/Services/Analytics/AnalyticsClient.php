<?php

namespace App\Services\Analytics;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes one daily aggregate snapshot to Dot.Analytics (POST /api/ingest/snapshots).
 *
 * Built to be harmless when things go wrong, in the same way as the Dot.Notify client. The
 * snapshot is sent from a queued job (never inside a request), the HTTP call has a 5 second
 * timeout, nothing here throws into the caller, a small circuit breaker stops hammering an
 * Analytics that is down, and with no ANALYTICS_INGEST_URL or ANALYTICS_INGEST_SECRET it does
 * nothing at all.
 *
 * Signing is Analytics' scheme: HMAC-SHA256 over "{timestamp}.{raw body}" with this platform's
 * secret, sent as "sha256=<hex>" in X-InfoDot-Signature with the unix time in
 * X-InfoDot-Timestamp. The signature is made at SEND time, so a retry carries a fresh timestamp
 * while the body (and its taken_at) stays the same, which is what lets Analytics treat a replay
 * as the same snapshot.
 *
 * Logs carry the platform, the company uuid and the day, never a secret or a metric value.
 */
class AnalyticsClient
{
    public const DONE = 'done';

    public const RETRY = 'retry';

    public const PAUSED = 'paused';

    public const GIVE_UP = 'give_up';

    private const FAILURES_KEY = 'analytics.circuit.failures';

    private const OPEN_UNTIL_KEY = 'analytics.circuit.open_until';

    public function enabled(): bool
    {
        return filled(config('analytics.url')) && filled(config('analytics.secret'));
    }

    /**
     * The request body for one company and one UTC day. `taken_at` is the start of that UTC day,
     * so every run on the same day (a retry, a second scheduler run) describes the same snapshot.
     *
     * @param  array<string, int|float|string>  $metrics  only keys of the contract (see MetricsCollector)
     * @return array<string, mixed>
     */
    public function payload(string $companyUuid, Carbon|\DateTimeInterface $takenAt, array $metrics): array
    {
        $platform = config('analytics.platform');

        return [
            'platform' => filled($platform) ? (string) $platform : 'ehail',
            'company_uuid' => $companyUuid,
            'taken_at' => Carbon::instance($takenAt)->utc()->startOfDay()->format('Y-m-d\TH:i:s\Z'),
            'period' => 'day',
            'metrics' => $metrics,
        ];
    }

    /**
     * POST one snapshot now. Returns what the queued job should do next; never throws.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(array $payload): string
    {
        if (! $this->enabled()) {
            return self::GIVE_UP;
        }

        if ($this->pausedFor() > 0) {
            return self::PAUSED;
        }

        $context = ['platform' => $payload['platform'] ?? null, 'company_uuid' => $payload['company_uuid'] ?? null, 'taken_at' => $payload['taken_at'] ?? null];

        try {
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = (int) config('analytics.timeout', 5);

            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('analytics.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('analytics.url'));
        } catch (ConnectionException $e) {
            $this->recordFailure();
            Log::warning('Dot.Analytics unreachable; will retry.', $context);

            return self::RETRY;
        } catch (Throwable $e) {
            $this->recordFailure();
            Log::warning('Dot.Analytics call failed unexpectedly; will retry.', $context + ['error' => class_basename($e)]);

            return self::RETRY;
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $this->recordSuccess();

            return self::DONE;
        }

        if ($status === 429 || $status >= 500) {
            $this->recordFailure();
            Log::warning('Dot.Analytics unavailable; will retry.', $context + ['status' => $status]);

            return self::RETRY;
        }

        // 401/403: our secret is wrong; 404: the company is unknown; 422: the payload is bad.
        // Retrying cannot fix these, and Analytics did answer, so it is not "down".
        $this->recordSuccess();
        Log::error('Dot.Analytics refused the snapshot.', $context + ['status' => $status]);

        return self::GIVE_UP;
    }

    /** Seconds until calls resume, 0 when the circuit is closed. */
    public function pausedFor(): int
    {
        try {
            return max(0, (int) Cache::get(self::OPEN_UNTIL_KEY, 0) - now()->timestamp);
        } catch (Throwable) {
            return 0;
        }
    }

    private function recordFailure(): void
    {
        try {
            Cache::add(self::FAILURES_KEY, 0, 3600);
            $failures = (int) Cache::increment(self::FAILURES_KEY);

            // The count is kept when the circuit opens, so the first call after the pause is a
            // probe: one more failure reopens it, one success closes it.
            if ($failures >= (int) config('analytics.circuit.threshold', 5)) {
                $pause = (int) config('analytics.circuit.pause_seconds', 60);
                Cache::put(self::OPEN_UNTIL_KEY, now()->timestamp + $pause, $pause + 60);
                Log::warning('Dot.Analytics circuit opened; pausing calls.', ['seconds' => $pause]);
            }
        } catch (Throwable) {
            // A broken cache must not break delivery or the caller.
        }
    }

    private function recordSuccess(): void
    {
        try {
            Cache::forget(self::FAILURES_KEY);
            Cache::forget(self::OPEN_UNTIL_KEY);
        } catch (Throwable) {
        }
    }
}
