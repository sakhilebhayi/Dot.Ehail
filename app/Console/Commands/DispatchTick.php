<?php

namespace App\Console\Commands;

use App\Domain\Dispatch\Dispatcher;
use App\Domain\Rides\IdempotentRequests;
use App\Domain\Rides\ScheduledRides;
use Illuminate\Console\Command;

/**
 * The scheduler's half of on-demand dispatch (D-2): every minute, move
 * scheduled rides on (reminders, allocation, missed slots), advance every
 * ride whose dispatch is due (lapsed offers, retries) and drop expired
 * idempotency keys. Polls from passengers and drivers do the same work
 * within seconds while anyone is watching; this covers the rest.
 */
class DispatchTick extends Command
{
    protected $signature = 'ehail:dispatch-tick {--limit=200 : most rides advanced in one run}';

    protected $description = 'Advance ride dispatch: scheduled rides, lapsed offers, retries';

    public function handle(Dispatcher $dispatcher, ScheduledRides $scheduled, IdempotentRequests $idempotency): int
    {
        $stats = $scheduled->run();
        $ticked = $dispatcher->tickDue((int) $this->option('limit'));
        $purged = $idempotency->purgeExpired();

        $this->info(sprintf(
            'Scheduled: %d reminded, %d dispatched, %d awaiting a fare answer, %d missed. Advanced %d searching ride(s). Purged %d idempotency key(s).',
            $stats['reminded'], $stats['activated'], $stats['requote_required'], $stats['expired'], $ticked, $purged,
        ));

        return self::SUCCESS;
    }
}
