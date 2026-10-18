<?php

namespace App\Jobs;

use App\Services\Analytics\AnalyticsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one company's daily snapshot to Dot.Analytics off the request path. Three tries with
 * backoff while Analytics is down or the circuit is open, gives up quietly on a refusal, and never
 * throws: a snapshot that cannot be delivered must not break anything. The payload is fixed when
 * the job is queued (numbers and taken_at), so every retry sends the same snapshot. Needs a queue
 * worker (php artisan queue:work); with QUEUE_CONNECTION=sync it runs inline.
 */
class SendAnalyticsSnapshot implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    /** @param  array<string, mixed>  $payload */
    public function __construct(public array $payload) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(AnalyticsClient $client): void
    {
        $delay = null;

        try {
            $outcome = $client->deliver($this->payload);
            $delay = $outcome === AnalyticsClient::PAUSED ? max(1, $client->pausedFor()) : null;
        } catch (Throwable) {
            $outcome = AnalyticsClient::RETRY;
        }

        if ($outcome !== AnalyticsClient::RETRY && $outcome !== AnalyticsClient::PAUSED) {
            return;
        }

        if ($this->job && $this->attempts() < $this->tries) {
            $this->release($delay ?? $this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)]);

            return;
        }

        if ($this->job) {
            Log::warning('Dot.Analytics snapshot abandoned after repeated failures.', [
                'platform' => $this->payload['platform'] ?? null,
                'company_uuid' => $this->payload['company_uuid'] ?? null,
                'taken_at' => $this->payload['taken_at'] ?? null,
            ]);
        }
    }
}
