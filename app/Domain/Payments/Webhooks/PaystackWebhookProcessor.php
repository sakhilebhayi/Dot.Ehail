<?php

namespace App\Domain\Payments\Webhooks;

use App\Domain\Earnings\Models\Payout;
use App\Domain\Earnings\Payouts\PayoutService;
use App\Domain\Payments\ChargeAttempts;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Models\WebhookEvent;
use App\Domain\Payments\PaymentMethods;
use App\Domain\Payments\Refunds;
use App\Services\Ehail\RidePayments;

/**
 * Applies one stored webhook. Nothing is trusted from the body beyond "look at this object": every
 * money state change is made from Paystack's own verify/fetch answer (transaction verify, refund
 * fetch, transfer verify). A Paystack outage throws, so the job retries.
 */
class PaystackWebhookProcessor
{
    public function __construct(
        private RidePayments $legacy,
        private ChargeAttempts $charges,
        private PaymentMethods $methods,
        private Refunds $refunds,
        private PayoutService $payouts,
    ) {}

    public function process(WebhookEvent $event): string
    {
        $payload = (array) $event->payload;
        $data = (array) ($payload['data'] ?? []);

        return match (true) {
            $event->event === 'charge.success' => $this->charge($payload, $data),
            str_starts_with($event->event, 'refund.') => $this->refund($data),
            str_starts_with($event->event, 'transfer.') => $this->transfer($data),
            default => 'ignored',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     */
    private function charge(array $payload, array $data): string
    {
        $reference = is_string($data['reference'] ?? null) ? $data['reference'] : null;
        $payment = $reference === null ? null : Payment::where('reference', $reference)->first();

        if ($payment === null || ($payment->purpose === Payment::PURPOSE_RIDE_FARE && $payment->status !== Payment::PENDING)) {
            // Legacy checkout payments, and charges we never issued (recorded for the desk).
            return 'legacy:'.$this->legacy->handleWebhook($payload);
        }

        return match ($payment->purpose) {
            Payment::PURPOSE_CARD_SETUP => 'card_setup:'.$this->methods->completeCardSetup($payment)['status'],
            default => 'charge:'.$this->charges->reconcile($payment)->status,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function refund(array $data): string
    {
        $id = $data['id'] ?? $data['refund_reference'] ?? null;
        $refund = is_scalar($id) ? Refund::where('method', 'paystack')->where('provider_refund_id', (string) $id)->first() : null;

        if ($refund === null) {
            $reference = $data['transaction_reference'] ?? ($data['transaction']['reference'] ?? null);
            $payment = is_string($reference) ? Payment::where('reference', $reference)->first() : null;
            $refund = $payment === null ? null : Refund::where('payment_id', $payment->id)->whereIn('status', [Refund::PENDING, Refund::PROCESSING])->latest('id')->first();
        }

        return $refund === null ? 'refund:unknown' : 'refund:'.$this->refunds->syncFromProvider($refund)->status;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function transfer(array $data): string
    {
        $reference = is_string($data['reference'] ?? null) ? $data['reference'] : null;
        $payout = $reference === null ? null : Payout::where('reference', $reference)->first();

        return $payout === null ? 'transfer:unknown' : 'transfer:'.$this->payouts->syncTransfer($payout)->status;
    }
}
