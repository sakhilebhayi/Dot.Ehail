<?php

namespace App\Console\Commands;

use App\Services\Ehail\RideLifecycle;
use Illuminate\Console\Command;

class ExpireRides extends Command
{
    protected $signature = 'ehail:expire-rides';

    protected $description = 'Expire ride requests nobody accepted and flag trips stuck in progress for operator review';

    public function handle(RideLifecycle $lifecycle): int
    {
        $expired = $lifecycle->expireStaleRequests();
        $flagged = $lifecycle->flagStuckTrips();

        $this->info("Expired {$expired} stale ride request(s).");
        $this->info("Flagged {$flagged} stuck trip(s) for review.");

        return self::SUCCESS;
    }
}
