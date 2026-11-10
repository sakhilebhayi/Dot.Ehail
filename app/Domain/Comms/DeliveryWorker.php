<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Mail\CommsMail;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Models\SmsNumber;
use App\Domain\Comms\Push\PushResult;
use App\Domain\Comms\Push\PushSubscriptions;
use App\Domain\Comms\Push\WebPushClient;
use App\Domain\Safety\Sms\SmsGateway;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Sends queued deliveries. Each row is claimed with a compare-and-swap (queued -> sending, with a
 * lease), so two workers never send the same copy; a lease that runs out (a worker died) puts the
 * row back in the queue. Outcomes are recorded as they really were: `sent` only when the channel
 * accepted the message, `queued` again with a backoff for temporary failures, `failed` after the
 * last attempt, `skipped` with the reason when sending would be pointless (a newer update about
 * the same ride overtook a push, the device is gone, email only logs).
 */
final class DeliveryWorker
{
    /** Push about a trip that is this old is no longer useful. */
    private const TRIP_PUSH_STALE_MINUTES = 30;

    public function __construct(
        private readonly WebPushClient $push,
        private readonly PushSubscriptions $subscriptions,
        private readonly SmsGateway $sms,
        private readonly ChannelStatus $status,
        private readonly CommsCategories $categories,
    ) {}

    /** @param list<int> $ids */
    public function deliverIds(array $ids): void
    {
        foreach ($ids as $id) {
            $this->process((int) $id);
        }
    }

    /** @return int how many deliveries were attempted */
    public function deliverDue(?int $limit = null): int
    {
        NotificationDelivery::where('status', NotificationDelivery::SENDING)
            ->where('claimed_until', '<', now())
            ->update(['status' => NotificationDelivery::QUEUED, 'claimed_until' => null, 'updated_at' => now()]);

        $ids = NotificationDelivery::where('status', NotificationDelivery::QUEUED)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit ?? (int) config('ehail_comms.delivery.batch', 100))
            ->pluck('id')->all();

        $this->deliverIds($ids);

        return count($ids);
    }

    public function process(int $id): void
    {
        $claimed = NotificationDelivery::whereKey($id)
            ->where('status', NotificationDelivery::QUEUED)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update([
                'status' => NotificationDelivery::SENDING,
                'claimed_until' => now()->addSeconds((int) config('ehail_comms.delivery.lease_seconds', 120)),
                'attempts' => DB::raw('attempts + 1'),
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return; // someone else has it, or it is not due
        }

        $delivery = NotificationDelivery::find($id);

        try {
            $this->send($delivery);
        } catch (Throwable $e) {
            Log::warning('Comms delivery crashed; will retry.', ['delivery_id' => $id, 'channel' => $delivery->channel, 'error' => class_basename($e)]);
            $this->retryOrFail($delivery, 'worker_error');
        }
    }

    private function send(NotificationDelivery $delivery): void
    {
        $user = User::find($delivery->user_id);

        if ($user === null) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'recipient_gone');

            return;
        }

        $record = $delivery->notification_id ? DatabaseNotification::find($delivery->notification_id) : null;

        if ($record === null) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'notification_gone');

            return;
        }

        $data = (array) $record->data;
        $title = (string) ($data['title'] ?? 'Dot.Ehail');
        $body = (string) ($data['message'] ?? '');
        $url = $this->sameOriginPath(isset($data['url']) ? (string) $data['url'] : null);

        match ($delivery->channel) {
            NotificationDelivery::PUSH => $this->sendPush($delivery, $user, $title, $body, $url),
            NotificationDelivery::EMAIL => $this->sendEmail($delivery, $user, $title, $body, $url),
            NotificationDelivery::SMS => $this->sendSms($delivery, $user, $title, $body),
            default => $this->finish($delivery, NotificationDelivery::SKIPPED, 'unknown_channel'),
        };
    }

    private function sendPush(NotificationDelivery $delivery, User $user, string $title, string $body, ?string $url): void
    {
        if ($this->overtaken($delivery)) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'superseded');

            return;
        }

        if ($delivery->category === 'trip' && $delivery->created_at?->lt(now()->subMinutes(self::TRIP_PUSH_STALE_MINUTES))) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'expired');

            return;
        }

        $subscriptions = $this->subscriptions->active($user);

        if ($subscriptions->isEmpty()) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'no_push_subscription');

            return;
        }

        $meta = (array) ($delivery->meta ?? []);
        $accepted = array_map('intval', (array) ($meta['accepted'] ?? []));
        $counts = ['accepted' => count($accepted), 'gone' => 0, 'failed' => 0, 'retry' => 0];
        $providerId = $delivery->provider_id;
        $lastReason = null;
        $critical = $this->categories->isCritical($delivery->category);

        $payload = [
            'title' => $title,
            'body' => mb_substr($body, 0, 240),
            'url' => $url ?? '/home',
            'tag' => $delivery->collapse_key ?? 'n-'.$delivery->notification_id,
            'critical' => $critical,
        ];

        foreach ($subscriptions as $subscription) {
            if (in_array($subscription->id, $accepted, true)) {
                continue;
            }

            $result = $this->push->send($subscription, $payload, $critical ? 'high' : 'normal', $delivery->collapse_key);

            switch ($result->status) {
                case PushResult::SENT:
                    $accepted[] = $subscription->id;
                    $counts['accepted']++;
                    $providerId ??= $result->providerId;
                    $subscription->forceFill(['last_success_at' => now(), 'failure_count' => 0])->save();
                    break;
                case PushResult::GONE:
                    $counts['gone']++;
                    $this->subscriptions->revoke($subscription, (string) $result->reason);
                    break;
                case PushResult::RETRY:
                    $counts['retry']++;
                    $lastReason = $result->reason;
                    $subscription->forceFill(['last_failure_at' => now(), 'failure_count' => $subscription->failure_count + 1])->save();
                    break;
                default:
                    $counts['failed']++;
                    $lastReason = $result->reason;
                    $subscription->forceFill(['last_failure_at' => now(), 'failure_count' => $subscription->failure_count + 1])->save();
            }
        }

        $delivery->forceFill(['meta' => ['accepted' => $accepted] + $counts, 'provider' => 'webpush', 'provider_id' => $providerId]);

        match (true) {
            $counts['retry'] > 0 => $this->retryOrFail($delivery, (string) $lastReason, $counts['accepted'] > 0),
            $counts['accepted'] > 0 => $this->finish($delivery, NotificationDelivery::SENT),
            $counts['failed'] > 0 => $this->finish($delivery, NotificationDelivery::FAILED, (string) $lastReason),
            default => $this->finish($delivery, NotificationDelivery::FAILED, 'subscription_gone'),
        };
    }

    private function sendEmail(NotificationDelivery $delivery, User $user, string $title, string $body, ?string $url): void
    {
        if (trim((string) $user->email) === '') {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'no_email_address');

            return;
        }

        if (! $this->status->mailDelivers()) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'mail_transport_not_delivering');

            return;
        }

        try {
            $sent = Mail::to($user->email)->send(new CommsMail(
                $title,
                $body,
                $url !== null ? url($url) : null,
                $this->categories->label($delivery->category),
                Route::has('comms.settings') ? route('comms.settings') : url('/home'),
            ));
        } catch (Throwable $e) {
            $this->retryOrFail($delivery, 'mail_error_'.strtolower(class_basename($e)));

            return;
        }

        $delivery->forceFill([
            'provider' => 'mail:'.$this->status->mailer(),
            'provider_id' => $sent !== null ? mb_substr((string) $sent->getMessageId(), 0, 191) : null,
        ]);
        $this->finish($delivery, NotificationDelivery::SENT);
    }

    private function sendSms(NotificationDelivery $delivery, User $user, string $title, string $body): void
    {
        if (! $this->status->smsConfigured()) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'sms_not_configured');

            return;
        }

        $number = SmsNumber::where('user_id', $user->id)->whereNotNull('verified_at')->first();

        if ($number === null) {
            $this->finish($delivery, NotificationDelivery::SKIPPED, 'no_confirmed_number');

            return;
        }

        $result = $this->sms->send((string) $number->number, mb_substr("Dot.Ehail: {$title}. {$body}", 0, 300));
        $delivery->forceFill(['provider' => $this->sms->name(), 'provider_id' => $result->providerMessageId]);

        match (true) {
            $result->wasSent() => $this->finish($delivery, NotificationDelivery::SENT),
            $result->status === 'skipped' => $this->finish($delivery, NotificationDelivery::SKIPPED, (string) $result->error),
            in_array($result->error, ['provider_unreachable', 'provider_error'], true) || str_starts_with((string) $result->error, 'provider_http_5') || $result->error === 'provider_http_429'
                => $this->retryOrFail($delivery, (string) $result->error),
            default => $this->finish($delivery, NotificationDelivery::FAILED, (string) $result->error),
        };
    }

    /** A newer push about the same thing (same collapse key) exists for this person: this one is stale. */
    private function overtaken(NotificationDelivery $delivery): bool
    {
        if ($delivery->collapse_key === null) {
            return false;
        }

        return NotificationDelivery::where('user_id', $delivery->user_id)
            ->where('channel', NotificationDelivery::PUSH)
            ->where('collapse_key', $delivery->collapse_key)
            ->where('id', '>', $delivery->id)
            ->whereIn('status', [NotificationDelivery::QUEUED, NotificationDelivery::SENDING, NotificationDelivery::SENT])
            ->exists();
    }

    private function retryOrFail(NotificationDelivery $delivery, string $reason, bool $partlySent = false): void
    {
        $backoff = (array) config('ehail_comms.delivery.backoff', [60, 300, 900, 3600]);
        $max = (int) config('ehail_comms.delivery.max_attempts', 5);

        if ($delivery->attempts >= $max) {
            // Some devices took it: that copy did arrive somewhere, so it is sent, with the rest noted.
            $this->finish($delivery, $partlySent ? NotificationDelivery::SENT : NotificationDelivery::FAILED, $reason);

            return;
        }

        $wait = (int) ($backoff[min($delivery->attempts - 1, count($backoff) - 1)] ?? 300);
        $delivery->forceFill([
            'status' => NotificationDelivery::QUEUED,
            'reason' => mb_substr($reason, 0, 160),
            'next_attempt_at' => now()->addSeconds($wait),
            'claimed_until' => null,
            'sent_at' => $partlySent ? ($delivery->sent_at ?? now()) : $delivery->sent_at,
        ])->save();
    }

    private function finish(NotificationDelivery $delivery, string $status, ?string $reason = null): void
    {
        $delivery->forceFill([
            'status' => $status,
            'reason' => $reason !== null ? mb_substr($reason, 0, 160) : null,
            'claimed_until' => null,
            'next_attempt_at' => null,
            'sent_at' => $status === NotificationDelivery::SENT ? ($delivery->sent_at ?? now()) : $delivery->sent_at,
        ])->save();
    }

    /** Only a path on this site goes into a push (the service worker opens nothing else). */
    private function sameOriginPath(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $parts = parse_url($url);
        $app = parse_url((string) config('app.url'));

        if (($parts['host'] ?? null) !== null && ($parts['host'] ?? null) === ($app['host'] ?? null)) {
            return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        return null;
    }
}
