<?php

namespace App\Domain\Safety\Console;

use App\Domain\Safety\CheckInService;
use App\Domain\Safety\IncidentService;
use Illuminate\Console\Command;

/** Every minute: unanswered check-ins become incidents; unacknowledged incidents escalate. */
class SafetySweep extends Command
{
    protected $signature = 'ehail:safety-sweep';

    protected $description = 'Raise incidents for unanswered safety check-ins and escalate unacknowledged incidents';

    public function handle(CheckInService $checkIns, IncidentService $incidents): int
    {
        $raised = $checkIns->sweepUnanswered();
        $escalated = $incidents->escalateDue();

        $this->info("Check-ins timed out: {$raised}. Incidents escalated: {$escalated}.");

        return self::SUCCESS;
    }
}
