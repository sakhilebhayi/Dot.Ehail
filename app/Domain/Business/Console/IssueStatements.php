<?php

namespace App\Domain\Business\Console;

use App\Domain\Business\BusinessClock;
use App\Domain\Business\Collections;
use App\Domain\Business\Statements;
use Illuminate\Console\Command;

/**
 * Daily: issue last month's statement for every account that has none yet, then run dunning
 * (overdue, reminders, suspension after grace, company-card collection, paid statements).
 */
class IssueStatements extends Command
{
    protected $signature = 'business:statements {--period= : YYYY-MM to issue (default: last month)}';

    protected $description = 'Issue monthly business statements and run dunning';

    public function handle(Statements $statements, Collections $collections): int
    {
        $period = (string) ($this->option('period') ?: BusinessClock::previousMonthKey());
        $issued = 0;

        foreach ($statements->accountsDue($period) as $account) {
            $statement = $statements->issue($account, $period);
            $issued++;

            if ($account->billing_mode === 'card') {
                $collections->collectByCard($statement);
            }
        }

        $counts = $collections->dunning();
        $this->info("Issued {$issued} statement(s) for {$period}; overdue {$counts['overdue']}, suspended {$counts['suspended']}, paid {$counts['paid']}, card attempts {$counts['card_attempts']}.");

        return self::SUCCESS;
    }
}
