<?php

namespace App\Jobs;

use App\Services\Notify\NotifyClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one event to Dot.Notify off the request path. Three tries with backoff while Notify is
 * down or the circuit is open, gives up quietly on a refusal, and never throws: an email that
 * cannot be sent must not break anything. Needs a queue worker (php artisan queue:work); with
 * QUEUE_CONNECTION=sync it runs inline.
 */
class SendNotifyEvent implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    /** @param  array<string, mixed>  $payload */
    public function __construct(public array $payload)
    {
        // Never queue from inside a transaction that may still roll back.
        $this->afterCommit = true;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(NotifyClient $client): void
    {
        $delay = null;

        try {
            $outcome = $client->deliver($this->payload);
            $delay = $outcome === NotifyClient::PAUSED ? max(1, $client->pausedFor()) : null;
        } catch (Throwable) {
            $outcome = NotifyClient::RETRY;
        }

        if ($outcome !== NotifyClient::RETRY && $outcome !== NotifyClient::PAUSED) {
            return;
        }

        if ($this->job && $this->attempts() < $this->tries) {
            $this->release($delay ?? $this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)]);

            return;
        }

        if ($this->job) {
            Log::warning('Dot.Notify event abandoned after repeated failures.', ['event' => $this->payload['event'] ?? null]);
        }
    }
}
