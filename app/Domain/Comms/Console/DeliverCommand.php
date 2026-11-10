<?php

namespace App\Domain\Comms\Console;

use App\Domain\Comms\DeliveryWorker;
use Illuminate\Console\Command;

/** Every minute: sends due deliveries (retries, and anything a lost job left queued). */
class DeliverCommand extends Command
{
    protected $signature = 'ehail:comms-deliver {--limit= : Deliveries to attempt in this run}';

    protected $description = 'Send queued notification deliveries (push, email, SMS) that are due';

    public function handle(DeliveryWorker $worker): int
    {
        $count = $worker->deliverDue($this->option('limit') !== null ? (int) $this->option('limit') : null);
        $this->info("Attempted {$count} deliveries.");

        return self::SUCCESS;
    }
}
