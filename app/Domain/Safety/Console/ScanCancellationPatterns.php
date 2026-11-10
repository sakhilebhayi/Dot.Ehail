<?php

namespace App\Domain\Safety\Console;

use App\Domain\Safety\CancellationPatternMonitor;
use Illuminate\Console\Command;

/** Hourly: flag (for review only) accounts with many cancellations after assignment. */
class ScanCancellationPatterns extends Command
{
    protected $signature = 'ehail:cancellation-patterns';

    protected $description = 'Flag repeated cancellations after a driver was assigned for operations review';

    public function handle(CancellationPatternMonitor $monitor): int
    {
        $this->info('Accounts flagged: '.$monitor->scan().'.');

        return self::SUCCESS;
    }
}
