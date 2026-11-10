<?php

namespace App\Domain\Comms\Console;

use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Models\PushSubscription;
use Illuminate\Console\Command;

/** Daily: deletes delivery records past the retention period and long-revoked push subscriptions. */
class PruneCommand extends Command
{
    protected $signature = 'ehail:comms-prune';

    protected $description = 'Delete old notification delivery records and revoked push subscriptions (retention)';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('ehail_comms.delivery.retention_days', 90));
        $deliveries = NotificationDelivery::where('created_at', '<', $cutoff)
            ->whereIn('status', [NotificationDelivery::SENT, NotificationDelivery::FAILED, NotificationDelivery::SKIPPED])
            ->delete();
        $subscriptions = PushSubscription::whereNotNull('revoked_at')->where('revoked_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deliveries} delivery records and {$subscriptions} revoked push subscriptions.");

        return self::SUCCESS;
    }
}
