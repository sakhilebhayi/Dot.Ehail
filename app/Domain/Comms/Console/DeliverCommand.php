<?php

namespace App\Domain\Comms\Console;

use App\Domain\Comms\DeliveryWorker;
use Illuminate\Console\Command;

/**
 * Every minute: sends due deliveries (retries, and anything a lost job left queued). It works in batches until
 * nothing is due or its time budget is spent (default 45 s, inside the one-minute cron window), so a backlog of a
 * thousand copies drains in one run instead of one batch a minute (docs/R46_PERF.md section 3, D-1509).
 */
class DeliverCommand extends Command
{
    protected $signature = 'ehail:comms-deliver
        {--limit= : Deliveries to attempt in this run (one batch, no time budget)}
        {--max-time=45 : Seconds to keep taking batches while deliveries are due}';

    protected $description = 'Send queued notification deliveries (push, email, SMS) that are due';

    public function handle(DeliveryWorker $worker): int
    {
        if ($this->option('limit') !== null) {
            $count = $worker->deliverDue((int) $this->option('limit'));
            $this->info("Attempted {$count} deliveries.");

            return self::SUCCESS;
        }

        $deadline = microtime(true) + max(1, (int) $this->option('max-time'));
        $total = 0;

        do {
            $count = $worker->deliverDue();
            $total += $count;
        } while ($count > 0 && microtime(true) < $deadline);

        $this->info("Attempted {$total} deliveries.");

        return self::SUCCESS;
    }
}
