<?php

namespace App\Domain\Perf;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Laravel's database session handler rewrites the session row on every request, even when nothing in it changed,
 * only to move last_activity. A driver app polling every 3 s would cost twenty writes a minute for nothing.
 *
 * This handler skips the write when the stored payload, user, IP and user agent are unchanged and the row's
 * last_activity is younger than the touch window (default 60 s). Expiry still works: the session lifetime is
 * minutes (SESSION_LIFETIME, 120), so a last_activity at most a minute old changes nothing for a person.
 * Any change to the session (login, CSRF token rotation, flash data, a different device) is written at once.
 */
class LazyTouchDatabaseSessionHandler extends DatabaseSessionHandler
{
    /** True when this request's session write was skipped (nothing changed): LeanPollResponses then omits the cookie. */
    public static bool $skippedWrite = false;

    private ?object $row = null;

    public function __construct($connection, $table, $minutes, $container, private readonly int $touchSeconds)
    {
        parent::__construct($connection, $table, $minutes, $container);
    }

    public function read($sessionId): string|false
    {
        self::$skippedWrite = false;
        $row = $this->getQuery()->find($sessionId);
        $this->row = $row === null ? null : (object) $row;

        if ($this->row !== null && $this->expired($this->row)) {
            $this->exists = true;

            return '';
        }

        if (isset($this->row->payload)) {
            $this->exists = true;

            return base64_decode($this->row->payload);
        }

        return '';
    }

    public function write($sessionId, $data): bool
    {
        if ($this->touchSeconds > 0 && $this->exists && $this->row !== null && $this->unchanged($data)) {
            self::$skippedWrite = true;

            return true;
        }

        self::$skippedWrite = false;

        $written = parent::write($sessionId, $data);
        $this->row = null;

        return $written;
    }

    private function unchanged(string $data): bool
    {
        $row = $this->row;

        if (! isset($row->payload, $row->last_activity) || $row->payload !== base64_encode($data)) {
            return false;
        }

        if ((int) $row->last_activity < now()->getTimestamp() - $this->touchSeconds) {
            return false;
        }

        $payload = $this->getDefaultPayload($data);

        return (string) ($payload['user_id'] ?? '') === (string) ($row->user_id ?? '')
            && (string) ($payload['ip_address'] ?? '') === (string) ($row->ip_address ?? '')
            && (string) ($payload['user_agent'] ?? '') === (string) ($row->user_agent ?? '');
    }
}
