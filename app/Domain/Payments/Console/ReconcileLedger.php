<?php

namespace App\Domain\Payments\Console;

use App\Domain\Ledger\Reconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReconcileLedger extends Command
{
    protected $signature = 'money:reconcile {--days=2}';

    protected $description = 'Check payments, refunds and payouts against the ledger and list mismatches for operations';

    public function handle(Reconciliation $reconciliation): int
    {
        $report = $reconciliation->report(CarbonImmutable::now()->subDays((int) $this->option('days')), CarbonImmutable::now(), recordIssues: true);
        $this->line(count($report['mismatches']).' mismatches; ledger '.($report['ledger']['balanced'] ? 'balanced' : 'NOT balanced'));

        return $report['ledger']['balanced'] ? self::SUCCESS : self::FAILURE;
    }
}
