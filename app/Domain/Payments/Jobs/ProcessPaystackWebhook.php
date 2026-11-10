<?php

namespace App\Domain\Payments\Jobs;

use App\Domain\Ledger\FinanceIssues;
use App\Domain\Payments\Models\WebhookEvent;
use App\Domain\Payments\Webhooks\PaystackWebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessPaystackWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $eventId) {}

    public function handle(PaystackWebhookProcessor $processor): void
    {
        // Claim the event so two workers (or a worker and the sweeper) never process it together.
        $claimed = DB::table('payment_webhook_events')->where('id', $this->eventId)
            ->whereIn('status', ['received', 'failed'])
            ->update(['status' => 'processing', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $event = WebhookEvent::findOrFail($this->eventId);

        try {
            $outcome = $processor->process($event);
        } catch (Throwable $e) {
            $event->forceFill(['status' => 'failed', 'outcome' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 120)])->save();

            throw $e;
        }

        $event->forceFill([
            'status' => $outcome === 'ignored' ? 'ignored' : 'processed',
            'outcome' => mb_substr($outcome, 0, 120),
            'processed_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $e): void
    {
        $event = WebhookEvent::find($this->eventId);

        if ($event !== null && $event->attempts >= $this->tries) {
            app(FinanceIssues::class)->raise('webhook_failed', "webhook-failed:{$event->id}", "Paystack {$event->event} could not be processed", [
                'subject_type' => 'webhook_event', 'subject_id' => $event->id, 'details' => ['reference' => $event->reference, 'outcome' => $event->outcome],
            ]);
        }
    }
}
