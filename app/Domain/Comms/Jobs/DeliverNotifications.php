<?php

namespace App\Domain\Comms\Jobs;

use App\Domain\Comms\DeliveryWorker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends the given queued deliveries. On the host the queue is worked by cron
 * (`queue:work --stop-when-empty` every minute); retries are not the queue's job but the delivery
 * rows' (next_attempt_at), picked up by `ehail:comms-deliver`, so a lost job loses nothing.
 */
class DeliverNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /** @param list<int> $deliveryIds */
    public function __construct(public array $deliveryIds) {}

    public function handle(DeliveryWorker $worker): void
    {
        $worker->deliverIds($this->deliveryIds);
    }
}
