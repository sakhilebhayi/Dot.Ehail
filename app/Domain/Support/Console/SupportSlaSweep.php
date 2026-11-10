<?php

namespace App\Domain\Support\Console;

use App\Domain\Support\SupportCaseService;
use Illuminate\Console\Command;

/** Every five minutes: escalate support cases whose first-response or resolution SLA ran out. */
class SupportSlaSweep extends Command
{
    protected $signature = 'ehail:support-sla';

    protected $description = 'Escalate support cases past their SLA';

    public function handle(SupportCaseService $cases): int
    {
        $this->info('Cases escalated: '.$cases->sweepSla().'.');

        return self::SUCCESS;
    }
}
