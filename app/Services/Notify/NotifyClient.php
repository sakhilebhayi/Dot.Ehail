<?php

namespace App\Services\Notify;

use App\Jobs\SendNotifyEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells Dot.Notify "this happened to this company, tell this person" (POST /api/ingest/events).
 *
 * Built to be harmless when things go wrong. The event is queued (after the surrounding
 * transaction commits; never sent inside the user's request), the HTTP call has a 5 second
 * timeout, nothing here throws into the caller, a small circuit breaker stops hammering a Notify
 * that is down, and with no NOTIFY_INGEST_URL or NOTIFY_INGEST_SECRET it does nothing at all.
 *
 * Signing is Notify's scheme: HMAC-SHA256 over "{timestamp}.{raw body}" with this platform's
 * secret, sent as "sha256=<hex>" in X-InfoDot-Signature with the unix time in
 * X-InfoDot-Timestamp. The signature is made at SEND time, so a retry carries a fresh timestamp.
 *
 * Who can be reached: Notify routes by company, so the recipient's company is their current
 * team's infodot_team_uuid, or failing that their personal team's. No uuid, or no email: skipped.
 * Logs carry the event name and ids, never an email address, a body or a secret.
 */
class NotifyClient
{
    public const DONE = 'done';

    public const RETRY = 'retry';

    public const PAUSED = 'paused';

    public const GIVE_UP = 'give_up';

    private const FAILURES_KEY = 'notify.circuit.failures';

    private const OPEN_UNTIL_KEY = 'notify.circuit.open_until';

    public function enabled(): bool
    {
        return filled(config('notify.url')) && filled(config('notify.secret'));
    }

    /**
     * Queue one event for one recipient. Never throws.
     *
     * @param  array<string, scalar|null>  $data  only the keys of the event's catalogue entry
     */
    public function queue(User $recipient, string $event, string|int $subject, string|int $bucket, array $data): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }

            $payload = $this->payload($recipient, $event, $subject, $bucket, $data);

            if ($payload === null) {
                Log::info('Notify event skipped: recipient has no InfoDot company or no email.', ['event' => $event, 'user_id' => $recipient->getKey()]);

                return;
            }

            // Only once the surrounding transaction commits (at once when there is none), so a
            // rolled-back action sends nothing. The callback runs after the commit, so it must
            // not let anything escape into the caller either.
            DB::afterCommit(function () use ($payload, $event) {
                try {
                    app(Dispatcher::class)->dispatch(new SendNotifyEvent($payload));
                } catch (Throwable $e) {
                    Log::warning('Could not queue a Notify event.', ['event' => $event, 'error' => class_basename($e)]);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Could not queue a Notify event.', ['event' => $event, 'error' => class_basename($e)]);
        }
    }

    /**
     * @param  array<string, scalar|null>  $data
     * @return array<string, mixed>|null null when the person cannot be addressed
     */
    public function payload(User $recipient, string $event, string|int $subject, string|int $bucket, array $data): ?array
    {
        $uuid = $this->companyUuid($recipient);
        $email = (string) $recipient->email;

        if (! filled($uuid) || trim($email) === '') {
            return null;
        }

        return [
            'platform' => (string) config('notify.platform', 'ehail'),
            'company_uuid' => $uuid,
            'event' => $event,
            'recipient' => ['email' => $email],
            'data' => $data,
            'idempotency_key' => $this->idempotencyKey($event, $subject, $email, $bucket),
        ];
    }

    /**
     * "{event}:{subject}:{sha1(email)}:{bucket}". The bucket is whatever makes this occurrence
     * unique but stable (a bid id, a settlement id), so a retry or a double dispatch collapses
     * at Notify while a genuinely new occurrence does not.
     */
    public function idempotencyKey(string $event, string|int $subject, string $email, string|int $bucket): string
    {
        $key = $event.':'.$subject.':'.sha1($email).':'.$bucket;

        // Notify accepts at most 120 characters.
        return strlen($key) <= 120 ? $key : substr($event, 0, 40).':'.sha1($key);
    }

    /**
     * Read only: resolving a company must not switch the user's current team (Jetstream's
     * currentTeam accessor would).
     */
    public function companyUuid(User $user): ?string
    {
        $current = $user->current_team_id ? Team::query()->find($user->current_team_id) : null;

        if ($current !== null && filled($current->infodot_team_uuid)) {
            return $current->infodot_team_uuid;
        }

        $personal = Team::query()->where('user_id', $user->getKey())->where('personal_team', true)->first();

        return filled($personal?->infodot_team_uuid) ? $personal->infodot_team_uuid : null;
    }

    /** An absolute https URL of a page in this app. */
    public function pageUrl(string $route, mixed $parameters = []): string
    {
        $url = route($route, $parameters, true);

        return str_starts_with($url, 'http://') ? 'https://'.substr($url, 7) : $url;
    }

    /**
     * POST one event now. Returns what the queued job should do next; never throws.
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

        try {
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = (int) config('notify.timeout', 5);

            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('notify.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('notify.url'));
        } catch (ConnectionException $e) {
            $this->recordFailure();
            Log::warning('Dot.Notify unreachable; will retry.', ['event' => $payload['event'] ?? null]);

            return self::RETRY;
        } catch (Throwable $e) {
            $this->recordFailure();
            Log::warning('Dot.Notify call failed unexpectedly; will retry.', ['event' => $payload['event'] ?? null, 'error' => class_basename($e)]);

            return self::RETRY;
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $this->recordSuccess();

            return self::DONE;
        }

        if ($status === 429 || $status >= 500) {
            $this->recordFailure();
            Log::warning('Dot.Notify unavailable; will retry.', ['event' => $payload['event'] ?? null, 'status' => $status]);

            return self::RETRY;
        }

        // 401/403: our secret is wrong; 404: the company is unknown; 422: the payload is bad.
        // Retrying cannot fix these, and Notify did answer, so it is not "down".
        $this->recordSuccess();
        Log::error('Dot.Notify refused the event.', ['event' => $payload['event'] ?? null, 'status' => $status]);

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
            if ($failures >= (int) config('notify.circuit.threshold', 5)) {
                $pause = (int) config('notify.circuit.pause_seconds', 60);
                Cache::put(self::OPEN_UNTIL_KEY, now()->timestamp + $pause, $pause + 60);
                Log::warning('Dot.Notify circuit opened; pausing calls.', ['seconds' => $pause]);
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
