<?php

namespace App\Domain\Comms\Push;

final readonly class PushResult
{
    public const SENT = 'sent';

    /** The subscription no longer exists (404/410): revoke it, never retry. */
    public const GONE = 'gone';

    /** Temporary (429, 5xx, network): retry later. */
    public const RETRY = 'retry';

    /** Refused for good (400, 403, 413, endpoint not allowed). */
    public const FAILED = 'failed';

    public function __construct(public string $status, public ?string $reason = null, public ?string $providerId = null, public ?int $retryAfter = null) {}
}
