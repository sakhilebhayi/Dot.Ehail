<?php

namespace App\Domain\Payments\Console;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Ledger\FinanceIssues;
use App\Domain\Payments\Jobs\SettleCompletedRide;
use App\Domain\Payments\Models\Payment;
use App\Enums\PaymentStatus;
use App\Models\Ride;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * Money must never be silently uncollected. Every five minutes: completed rides with a fare due
 * (payment_status unpaid) whose settlement never ran (no driver earning for the trip, no fare charge)
 * once money.settlement_sweep.after_minutes have passed are queued for settlement again (idempotent:
 * RideSettlement does nothing twice) and listed on the finance exceptions list, so a person sees that the
 * normal path failed and why it matters.
 */
class SettlementSweep extends Command
{
    protected $signature = 'ehail:settlement-sweep';

    protected $description = 'Queue settlement for completed rides that were never settled, and list them for finance';

    public function handle(FinanceIssues $issues, Cache $cache): int
    {
        $after = max(11, (int) config('money.settlement_sweep.after_minutes', 15));
        $lookback = max(1, (int) config('money.settlement_sweep.lookback_days', 7));
        $queued = 0;

        Ride::query()
            ->where('status', 'completed')
            ->whereNotNull('driver_id')
            ->where('payment_status', PaymentStatus::Unpaid->value)
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', now()->subMinutes($after))
            ->where('completed_at', '>=', now()->subDays($lookback))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ride_payments')
                ->whereColumn('ride_payments.ride_id', 'rides.id')->where('purpose', Payment::PURPOSE_RIDE_FARE))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('driver_earnings')
                ->whereColumn('driver_earnings.ride_id', 'rides.id')->where('kind', DriverEarning::KIND_TRIP))
            ->orderBy('id')->limit(100)->get()
            ->each(function (Ride $ride) use ($issues, $cache, &$queued) {
                $job = new SettleCompletedRide($ride->id);
                // Past the lock's lifetime a held lock belongs to a job that was lost; it must not block this one.
                (new UniqueLock($cache))->release($job);
                dispatch($job);

                $issues->raise('settlement_missing', "settlement-missing:ride:{$ride->id}",
                    "Ride #{$ride->id} completed with a fare but was never settled; settlement was queued again", [
                        'severity' => 'critical', 'subject_type' => 'ride', 'subject_id' => $ride->id, 'ride_id' => $ride->id,
                        'amount_minor' => $ride->final_fare_minor, 'currency' => $ride->currency,
                        'details' => ['completed_at' => $ride->completed_at?->toIso8601String()],
                    ]);
                Log::warning('ehail.settlement_missing', ['ride_id' => $ride->id, 'completed_at' => $ride->completed_at?->toIso8601String()]);
                $queued++;
            });

        $this->line("queued {$queued}");

        return self::SUCCESS;
    }
}
