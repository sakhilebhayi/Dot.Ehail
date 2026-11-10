<?php

namespace App\Domain\Health;

use App\Domain\Geo\Support\CircuitBreaker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Operational health of Dot.Ehail on a cron-driven shared host (docs/R46_PERF.md section 6): what has to keep
 * moving for rides to move. Used by `php artisan ehail:health`, the ops status data source (GET /ops/status) and,
 * reduced to ok/degraded, the public /up/realtime.
 *
 * Every check reports {status: ok|degraded|failing|unknown|off, value, summary}; a check that cannot run says
 * "unknown" with the reason instead of guessing. No personal data: counts, ages and states only.
 */
class HealthReport
{
    public const OK = 'ok';

    public const DEGRADED = 'degraded';

    public const FAILING = 'failing';

    public const UNKNOWN = 'unknown';

    /** Not in use on this deployment (e.g. no routing provider configured): not a fault. */
    public const OFF = 'off';

    public const SCHEDULE_KEY = 'ehail:health:schedule-ran';

    public const TICK_KEY = 'ehail:health:dispatch-tick';

    public function __construct(private readonly CircuitBreaker $breaker) {}

    /**
     * @return array{status: string, mode: string, checked_at: string, checks: array<string, array{status: string, value: mixed, summary: string}>}
     */
    public function run(): array
    {
        $checks = [];

        foreach ([
            'database' => fn () => $this->database(),
            'scheduler' => fn () => $this->scheduler(),
            'dispatch' => fn () => $this->dispatch(),
            'queue' => fn () => $this->queue(),
            'failed_jobs' => fn () => $this->failedJobs(),
            'drivers' => fn () => $this->drivers(),
            'payment_webhooks' => fn () => $this->webhooks(),
            'notifications' => fn () => $this->notifications(),
            'routing' => fn () => $this->provider('routing', (string) config('ehail-geo.routing.driver')),
            'geocoding' => fn () => $this->provider('geocoding', (string) config('ehail-geo.geocoding.driver')),
        ] as $name => $check) {
            try {
                $checks[$name] = $check();
            } catch (Throwable $e) {
                $checks[$name] = $this->result($name === 'database' ? self::FAILING : self::UNKNOWN, null, 'Could not run this check ('.class_basename($e).').');
            }
        }

        return [
            'status' => self::worst(array_column($checks, 'status')),
            'mode' => self::realtimeMode(),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /**
     * Polling mode's real-time chain for /up/realtime: the database answers and the per-minute dispatch tick has run
     * recently (it moves rides nobody is polling and expires lapsed offers). Polls themselves need nothing else.
     *
     * @return array<string, array{status: string, value: mixed, summary: string}>
     */
    public function pollingChain(): array
    {
        $checks = [];

        foreach (['database' => fn () => $this->database(), 'dispatch_tick' => fn () => $this->tickOnly()] as $name => $check) {
            try {
                $checks[$name] = $check();
            } catch (Throwable $e) {
                $checks[$name] = $this->result(self::FAILING, null, 'Could not run this check ('.class_basename($e).').');
            }
        }

        return $checks;
    }

    private function tickOnly(): array
    {
        $age = $this->age(self::TICK_KEY);

        if ($age === null) {
            return $this->result(self::FAILING, null, 'The per-minute dispatch tick has never run: the host cron (schedule:run) is missing.');
        }

        $fail = $this->threshold('dispatch_tick_fail_s');

        return $this->result($age >= $fail ? self::FAILING : self::OK, ['tick_age_s' => $age], "Dispatch tick last ran {$age} s ago.");
    }

    /**
     * "polling" (the designed shared-host mode: short polls, dispatch advanced by polls and the cron) or
     * "websocket" (Reverb configured for broadcasting).
     */
    public static function realtimeMode(): string
    {
        return config('broadcasting.default') === 'reverb' ? 'websocket' : 'polling';
    }

    /**
     * @param  list<string>  $statuses
     */
    public static function worst(array $statuses): string
    {
        foreach ([self::FAILING, self::DEGRADED] as $level) {
            if (in_array($level, $statuses, true)) {
                return $level;
            }
        }

        return self::OK;
    }

    private function result(string $status, mixed $value, string $summary): array
    {
        return ['status' => $status, 'value' => $value, 'summary' => $summary];
    }

    private function threshold(string $key): int|float
    {
        return config('ehail_perf.health.'.$key);
    }

    private function level(float $value, float $warn, ?float $fail): string
    {
        return match (true) {
            $fail !== null && $value >= $fail => self::FAILING,
            $value >= $warn => self::DEGRADED,
            default => self::OK,
        };
    }

    private function database(): array
    {
        $started = hrtime(true);
        DB::select('select 1');
        $ms = (int) round((hrtime(true) - $started) / 1e6);

        return $this->result($ms > 500 ? self::DEGRADED : self::OK, ['latency_ms' => $ms], "Database answered in {$ms} ms.");
    }

    private function age(string $cacheKey): ?int
    {
        $at = Cache::get($cacheKey);

        return is_int($at) ? max(0, now()->getTimestamp() - $at) : null;
    }

    private function scheduler(): array
    {
        $age = $this->age(self::SCHEDULE_KEY);

        if ($age === null) {
            return $this->result(self::DEGRADED, null, 'The scheduler has not run since the cache was last cleared. The host cron must run `php artisan schedule:run` every minute.');
        }

        return $this->result($this->level($age, $this->threshold('schedule_warn_s'), $this->threshold('schedule_warn_s') * 4), ['age_s' => $age], "Scheduler last ran {$age} s ago.");
    }

    private function dispatch(): array
    {
        $age = $this->age(self::TICK_KEY);
        $grace = now()->subSeconds(60);

        // Searching rides whose next dispatch step is a minute overdue: nothing (no poll, no cron) has moved them.
        $overdue = DB::table('rides')->whereIn('status', ['requested', 'searching'])
            ->where(fn ($q) => $q->whereNull('next_dispatch_at')->orWhere('next_dispatch_at', '<=', $grace))
            ->where(fn ($q) => $q->whereNull('pending_offer_expires_at')->orWhere('pending_offer_expires_at', '<=', $grace))
            ->where('updated_at', '<=', $grace)
            ->count();

        $status = $age === null ? self::DEGRADED : $this->level($age, $this->threshold('dispatch_tick_warn_s'), $this->threshold('dispatch_tick_fail_s'));

        if ($overdue > 0) {
            $status = self::worst([$status, self::DEGRADED]);
        }

        $summary = ($age === null ? 'The per-minute dispatch tick has not run yet.' : "Dispatch tick last ran {$age} s ago.")
            .($overdue > 0 ? " {$overdue} searching ride(s) have not moved for over a minute." : '');

        return $this->result($status, ['tick_age_s' => $age, 'overdue_rides' => $overdue], $summary);
    }

    private function queue(): array
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            return $this->result(self::OK, ['connection' => 'sync'], 'Jobs run inside the request (sync queue).');
        }

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return $this->result(self::UNKNOWN, ['connection' => $connection], "Queue \"{$connection}\" is not database-backed; its lag is not measured here.");
        }

        $table = (string) config("queue.connections.{$connection}.table", 'jobs');
        $now = now()->getTimestamp();
        $pending = DB::table($table)->whereNull('reserved_at')->where('available_at', '<=', $now)->count();
        $oldest = DB::table($table)->whereNull('reserved_at')->where('available_at', '<=', $now)->min('available_at');
        $lag = $oldest === null ? 0 : max(0, $now - (int) $oldest);

        return $this->result(
            $this->level($lag, $this->threshold('queue_lag_warn_s'), $this->threshold('queue_lag_fail_s')),
            ['pending' => $pending, 'lag_s' => $lag],
            $pending === 0 ? 'No jobs waiting.' : "{$pending} job(s) waiting, the oldest for {$lag} s (the cron worker runs once a minute).",
        );
    }

    private function failedJobs(): array
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');
        $count = DB::table($table)->where('failed_at', '>=', now()->subDay())->count();

        return $this->result($this->level($count, $this->threshold('failed_jobs_warn'), $this->threshold('failed_jobs_fail')), ['last_24h' => $count], "{$count} job(s) failed in the last 24 hours.");
    }

    private function drivers(): array
    {
        $stale = now()->subSeconds((int) config('ehail_dispatch.heartbeat_stale_seconds'));
        $online = DB::table('driver_availability')->whereIn('state', ['online', 'paused', 'on_trip'])->count();
        $silent = DB::table('driver_availability')->whereIn('state', ['online', 'paused', 'on_trip'])
            ->where(fn ($q) => $q->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', $stale))->count();
        $share = $online === 0 ? 0.0 : $silent / $online;

        // A few silent phones are normal (tunnels, closed apps); most of them silent points at the server.
        $status = $online < 5 ? self::OK : $this->level($share, $this->threshold('stale_driver_share_warn'), $this->threshold('stale_driver_share_fail'));

        return $this->result($status, ['online' => $online, 'silent' => $silent], "{$online} driver(s) online, {$silent} not heard from within the heartbeat window.");
    }

    private function webhooks(): array
    {
        $waiting = DB::table('payment_webhook_events')->where('status', 'received');
        $count = (clone $waiting)->count();
        $oldest = (clone $waiting)->min('created_at');
        $lag = $oldest === null ? 0 : (int) max(0, Carbon::parse((string) $oldest)->diffInSeconds(now(), false));
        $failed = DB::table('payment_webhook_events')->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count();
        $status = self::worst([$this->level($lag, $this->threshold('webhook_lag_warn_s'), $this->threshold('webhook_lag_fail_s')), $failed > 0 ? self::DEGRADED : self::OK]);

        return $this->result($status, ['unprocessed' => $count, 'lag_s' => $lag, 'failed_24h' => $failed],
            ($count === 0 ? 'Every payment webhook received has been processed.' : "{$count} payment webhook(s) waiting, the oldest for {$lag} s.").($failed > 0 ? " {$failed} failed in 24 hours." : ''));
    }

    private function notifications(): array
    {
        $now = now();
        $due = DB::table('notification_deliveries')->where('status', 'queued')->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now));
        $dueCount = (clone $due)->count();
        $failed = DB::table('notification_deliveries')->where('status', 'failed')->where('updated_at', '>=', $now->copy()->subDay())->count();
        $status = self::worst([
            $this->level($failed, $this->threshold('notification_failed_warn'), $this->threshold('notification_failed_fail')),
            $this->level($dueCount, $this->threshold('notification_due_warn'), null),
        ]);

        return $this->result($status, ['due' => $dueCount, 'failed_24h' => $failed], "{$dueCount} delivery(ies) due, {$failed} failed in the last 24 hours.");
    }

    private function provider(string $kind, string $driver): array
    {
        if ($driver === '' || $driver === 'none') {
            return $this->result(self::OFF, ['driver' => 'none'], ucfirst($kind).' provider not configured (the app says so to users).');
        }

        $open = $this->breaker->isOpen($kind.':'.$driver);

        return $this->result($open ? self::DEGRADED : self::OK, ['driver' => $driver, 'circuit' => $open ? 'open' : 'closed'],
            $open ? ucfirst($kind)." provider {$driver} failed repeatedly: calls are paused for a minute (circuit open)." : ucfirst($kind)." provider {$driver}: circuit closed.");
    }
}
