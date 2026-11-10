<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Jobs\DeliverNotifications;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Models\PushSubscription;
use App\Domain\Comms\Models\SmsNumber;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides, for one in-app notification, which extra copies (push, email, SMS) to make, and writes
 * one notification_deliveries row per relevant channel: `queued` for the worker, or `skipped` with
 * the reason (preference off, own action, provider not configured, no subscription, Dot.Notify
 * sends it). Rows are idempotent per event, person and channel. Queued rows are handed to the
 * worker after the surrounding transaction commits: at once (after the response) for critical
 * categories, through the queue otherwise.
 */
final class FanOut
{
    public function __construct(
        private readonly CommsCategories $categories,
        private readonly Preferences $preferences,
        private readonly ChannelStatus $status,
    ) {}

    /**
     * @param  bool  $actorIsRecipient  the person caused this themselves (no push or SMS for that)
     * @param  Notification|null  $source  the other domain's notification, for the Dot.Notify precedence rule
     * @return list<int> ids of queued deliveries
     */
    public function plan(User $user, string $notificationId, string $type, string $eventKey, ?string $collapseKey, bool $actorIsRecipient, ?Notification $source = null): array
    {
        $category = $this->categories->categoryFor($type);

        if ($this->categories->isInAppOnlyType($type)) {
            return [];
        }

        $queued = [];

        foreach ($this->categories->channels($category) as $channel) {
            $decision = $this->decide($user, $channel, $category, $type, $actorIsRecipient, $source);

            if ($decision === false) {
                continue;
            }

            $now = now();
            DB::table('notification_deliveries')->insertOrIgnore([
                'event_key' => $eventKey,
                'user_id' => $user->id,
                'channel' => $channel,
                'category' => $category,
                'type' => $type,
                'notification_id' => $notificationId,
                'status' => $decision === true ? NotificationDelivery::QUEUED : NotificationDelivery::SKIPPED,
                'reason' => $decision === true ? null : $decision,
                'collapse_key' => $collapseKey,
                'next_attempt_at' => $decision === true ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($decision === true) {
                $id = NotificationDelivery::where('event_key', $eventKey)->where('user_id', $user->id)->where('channel', $channel)
                    ->where('status', NotificationDelivery::QUEUED)->value('id');
                if ($id !== null) {
                    $queued[] = (int) $id;
                }
            }
        }

        if ($queued !== []) {
            $this->handToWorker($queued, $this->categories->isCritical($category));
        }

        return $queued;
    }

    /**
     * true: queue it. string: record it as skipped with this reason. false: the channel does not
     * apply (no row).
     */
    private function decide(User $user, string $channel, string $category, string $type, bool $actorIsRecipient, ?Notification $source): bool|string
    {
        if ($channel === 'email' && isset(config('ehail_comms.email_sent_elsewhere', [])[$type])) {
            return false; // the other domain's own email is recorded by MailNotificationListener
        }

        if (! $this->preferences->allows($user, $category, $channel)) {
            return 'preference_off';
        }

        if ($actorIsRecipient && $channel !== 'email') {
            return 'own_action';
        }

        return match ($channel) {
            'push' => $this->pushDecision($user),
            'email' => $this->emailDecision($user, $type, $source),
            'sms' => $this->smsDecision($user, $category),
            default => false,
        };
    }

    private function pushDecision(User $user): bool|string
    {
        if (! $this->status->pushConfigured()) {
            return 'push_not_configured';
        }

        return PushSubscription::active()->where('user_id', $user->id)->exists() ? true : 'no_push_subscription';
    }

    private function emailDecision(User $user, string $type, ?Notification $source): bool|string
    {
        if (trim((string) $user->email) === '') {
            return 'no_email_address';
        }

        if ($this->status->notifyEnabled() && NotifyPrecedence::handledByNotify($type, $user, $source)) {
            return 'sent_by_dot_notify';
        }

        return $this->status->mailDelivers() ? true : 'mail_transport_not_delivering';
    }

    private function smsDecision(User $user, string $category): bool|string
    {
        if (! $this->categories->isCritical($category)) {
            return false;
        }

        if (! $this->status->smsConfigured()) {
            return 'sms_not_configured';
        }

        return SmsNumber::where('user_id', $user->id)->whereNotNull('verified_at')->exists() ? true : 'no_confirmed_number';
    }

    /** @param list<int> $ids */
    private function handToWorker(array $ids, bool $urgent): void
    {
        DB::afterCommit(function () use ($ids, $urgent) {
            try {
                // Critical (safety): now, not at the next cron minute; after the response on the
                // web, in place in a console run (the safety sweep, a queue worker).
                match (true) {
                    $urgent && app()->runningInConsole() => DeliverNotifications::dispatchSync($ids),
                    $urgent => DeliverNotifications::dispatchAfterResponse($ids),
                    default => DeliverNotifications::dispatch($ids),
                };
            } catch (Throwable $e) {
                // The rows stay queued; the per-minute ehail:comms-deliver run picks them up.
                Log::warning('Could not hand deliveries to the worker; the scheduled run will.', ['count' => count($ids), 'error' => class_basename($e)]);
            }
        });
    }
}
