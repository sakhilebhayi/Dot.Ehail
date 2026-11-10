<?php

namespace App\Domain\Payments\Console;

use App\Domain\Earnings\Models\Payout;
use App\Domain\Earnings\Payouts\PayoutService;
use App\Domain\Payments\ChargeAttempts;
use App\Domain\Payments\Jobs\ProcessPaystackWebhook;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Models\WebhookEvent;
use App\Domain\Payments\Refunds;
use App\Domain\Payments\RideSettlement;
use Illuminate\Console\Command;
use Throwable;

/**
 * Every minute (no daemons on the host): verify charges with no final answer, retry declined charges
 * whose time has come, re-run webhooks that failed, and re-check open refunds and transfers.
 */
class MoneySweep extends Command
{
    protected $signature = 'money:sweep';

    protected $description = 'Verify pending charges, retry due charges, reprocess webhooks, sync refunds and transfers';

    public function handle(ChargeAttempts $charges, RideSettlement $routes, Refunds $refunds, PayoutService $payouts): int
    {
        $verifyAfter = now()->subSeconds(max(10, (int) config('money.charging.pending_verify_after_seconds', 90)));
        $verified = 0;

        Payment::where('status', Payment::PENDING)->where('updated_at', '<=', $verifyAfter)->orderBy('id')->limit(100)->get()
            ->each(function (Payment $p) use ($charges, &$verified) {
                $charges->reconcile($p);
                $verified++;
            });

        $retried = $charges->retryDue(fn ($ride, $failed) => $routes->retryMethod($ride, $failed));

        $webhooks = 0;
        WebhookEvent::whereIn('status', ['received', 'failed'])->where('attempts', '<', 5)->where('updated_at', '<=', now()->subMinute())->orderBy('id')->limit(50)->get()
            ->each(function (WebhookEvent $e) use (&$webhooks) {
                try {
                    ProcessPaystackWebhook::dispatchSync($e->id);
                } catch (Throwable) {
                    // stays failed; the next run tries again until attempts run out
                }
                $webhooks++;
            });

        Refund::where('method', 'paystack')->whereIn('status', [Refund::PENDING, Refund::PROCESSING])->where('updated_at', '<=', now()->subMinutes(15))->limit(50)->get()
            ->each(fn (Refund $r) => $refunds->syncFromProvider($r));

        Refund::where('status', Refund::REQUESTED)->where('created_at', '<=', now()->subMinutes(5))->limit(50)->get()
            ->each(fn (Refund $r) => $refunds->submit($r));

        if ($payouts->transfersEnabled()) {
            Payout::where('status', Payout::PROCESSING)->where('updated_at', '<=', now()->subMinutes(5))->limit(50)->get()
                ->each(fn (Payout $p) => $payouts->syncTransfer($p));
        }

        $this->line("verified {$verified}, retried {$retried}, webhooks {$webhooks}");

        return self::SUCCESS;
    }
}
