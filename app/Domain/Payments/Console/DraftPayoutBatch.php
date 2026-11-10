<?php

namespace App\Domain\Payments\Console;

use App\Domain\Earnings\Payouts\PayoutService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * On the configured payout day: draft the batch for earnings up to the end of yesterday. Drafting
 * moves no money; an operator exports and pays it (or sends Paystack transfers when enabled).
 */
class DraftPayoutBatch extends Command
{
    protected $signature = 'money:draft-payouts {--period-end= : Y-m-d, default yesterday} {--force : ignore the schedule day}';

    protected $description = 'Draft the weekly driver payout batch';

    public function handle(PayoutService $payouts): int
    {
        if (! $this->option('force') && (int) now()->isoWeekday() !== (int) config('money.payouts.schedule_day', 1)) {
            $this->line('Not the payout day.');

            return self::SUCCESS;
        }

        $end = $this->option('period-end') ? CarbonImmutable::parse($this->option('period-end')) : CarbonImmutable::yesterday();
        $batch = $payouts->draftBatch($end);
        $this->line("batch {$batch->id}: {$batch->payout_count} payouts, {$batch->total_minor} minor units");

        return self::SUCCESS;
    }
}
