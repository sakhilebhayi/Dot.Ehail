<?php

namespace App\Domain\Payments\Webhooks;

use App\Domain\Payments\Jobs\ProcessPaystackWebhook;
use App\Domain\Payments\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores an authenticated Paystack webhook once and queues its processing. Paystack sends no event id,
 * so the identity is a hash of the event name and the object's id, reference, status and code: a
 * redelivery of the same event is recognised and acknowledged without being processed again, while
 * a later status of the same object (refund.pending then refund.processed) is a new event.
 */
class PaystackWebhookInbox
{
    /**
     * @param  array<string, mixed>  $payload
     * @return 'queued'|'duplicate'
     */
    public function receive(array $payload): string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $event = is_string($payload['event'] ?? null) ? mb_substr($payload['event'], 0, 60) : 'unknown';
        $reference = $data['reference'] ?? $data['transaction_reference'] ?? null;

        $key = hash('sha256', implode('|', [
            $event,
            is_scalar($data['id'] ?? null) ? (string) $data['id'] : '',
            is_scalar($reference) ? (string) $reference : '',
            is_scalar($data['status'] ?? null) ? (string) $data['status'] : '',
            is_scalar($data['transfer_code'] ?? null) ? (string) $data['transfer_code'] : '',
            is_scalar($data['refund_reference'] ?? null) ? (string) $data['refund_reference'] : '',
        ]));

        try {
            $stored = WebhookEvent::create([
                'provider' => 'paystack',
                'event_key' => $key,
                'event' => $event,
                'reference' => is_scalar($reference) ? mb_substr((string) $reference, 0, 120) : null,
                'payload' => ['event' => $event, 'data' => self::withoutPersonalData($data)],
                'status' => 'received',
            ]);
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }

        try {
            ProcessPaystackWebhook::dispatch($stored->id);
        } catch (Throwable $e) {
            // Stored is acknowledged: the sweeper (money:sweep) processes it again later.
            Log::warning('paystack.webhook_processing_deferred', ['event_id' => $stored->id, 'error' => class_basename($e)]);
        }

        return 'queued';
    }

    /**
     * Customer and card details are never kept from a webhook body.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withoutPersonalData(array $data): array
    {
        unset($data['customer'], $data['authorization'], $data['recipient'], $data['log'], $data['ip_address'], $data['metadata']);

        if (isset($data['transaction']) && is_array($data['transaction'])) {
            $data['transaction'] = array_intersect_key($data['transaction'], array_flip(['id', 'reference', 'amount', 'currency', 'status']));
        }

        return $data;
    }
}
