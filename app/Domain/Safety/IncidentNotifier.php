<?php

namespace App\Domain\Safety;

use App\Domain\Safety\Mail\IncidentEscalationMail;
use App\Domain\Safety\Mail\SosAlertMail;
use App\Domain\Safety\Models\IncidentNotification;
use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Safety\Models\TrustedContact;
use App\Domain\Safety\Notifications\SafetyIncidentAlertNotification;
use App\Domain\Safety\Sms\SmsGateway;
use App\Models\User;
use App\Services\Notify\NotifyClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends everything an incident causes and records each attempt with its real outcome in
 * incident_notifications. This is the TrustedContactNotifier of the contract (email always via
 * the mail channel, SMS only when an SMS provider is configured) plus the operations alerts.
 */
class IncidentNotifier
{
    public function __construct(
        private readonly MailDelivery $mail,
        private readonly SmsGateway $sms,
        private readonly NotifyClient $notify,
        private readonly TrustedContactService $contacts,
        private readonly IncidentTimeline $timeline,
    ) {}

    /**
     * Confirmed, not-opted-out contacts of the reporter: one email each, one SMS each when SMS is
     * configured and the contact has a mobile number. A contact list that is empty is recorded too.
     */
    public function notifyTrustedContacts(SafetyIncident $incident, User $person, ?string $shareUrl): void
    {
        $contacts = TrustedContact::query()->where('user_id', $person->id)->usable()->orderBy('id')->get();

        if ($contacts->isEmpty()) {
            $this->record($incident, 'none', 'trusted_contact', null, 'No confirmed trusted contacts', 'sos_alert', IncidentNotification::SKIPPED, 'no_confirmed_contacts');

            return;
        }

        $vehicle = $incident->context['vehicle']['description'] ?? null;
        $location = $incident->location_lat !== null
            ? sprintf('%.5f, %.5f (https://www.openstreetmap.org/?mlat=%.5f&mlon=%.5f)', $incident->location_lat, $incident->location_lng, $incident->location_lat, $incident->location_lng)
            : null;
        $numbers = $this->emergencyNumbers();
        $firstName = RideSafety::firstName($person->name, 'Your contact');

        foreach ($contacts as $contact) {
            $row = $this->record($incident, 'mail', 'trusted_contact', $contact->id, 'Email to '.$contact->maskedLabel(), 'sos_alert', IncidentNotification::PENDING);
            $result = $this->mail->send((string) $contact->email, new SosAlertMail(
                (string) $contact->name,
                $firstName,
                $incident->reference,
                $incident->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                $shareUrl,
                $vehicle,
                $location,
                $numbers,
                $this->contacts->optOutUrl($contact),
            ));
            $this->finish($row, $result['status'], $result['error']);

            if (blank($contact->phone)) {
                continue;
            }

            $smsLabel = 'SMS to '.mb_substr((string) $contact->name, 0, 40).' (…'.substr((string) $contact->phone, -3).')';

            if (! $this->sms->isConfigured()) {
                $this->record($incident, 'sms', 'trusted_contact', $contact->id, $smsLabel, 'sos_alert', IncidentNotification::SKIPPED, 'sms_not_configured');

                continue;
            }

            $smsRow = $this->record($incident, 'sms', 'trusted_contact', $contact->id, $smsLabel, 'sos_alert', IncidentNotification::PENDING);
            $text = "Dot.Ehail safety alert {$incident->reference}: {$firstName} pressed the emergency button."
                .($shareUrl ? " Follow the trip: {$shareUrl}" : '')
                .' Dot.Ehail does not dispatch emergency services. Police 10111, ambulance 10177, mobile 112.';
            $sent = $this->sms->send((string) $contact->phone, $text);
            $this->finish($smsRow, $sent->status, $sent->error, $sent->providerMessageId);
        }
    }

    /**
     * Every platform operator gets an in-app alert; Dot.Notify gets the same event when configured
     * (queued: this app cannot see whether Notify then emails).
     */
    public function alertOperations(SafetyIncident $incident, string $reason = 'raised'): void
    {
        $operators = User::query()->where('is_platform_operator', true)->orderBy('id')->get();

        if ($operators->isEmpty()) {
            $this->record($incident, 'database', 'operations', null, 'Operations team (no operator accounts exist)', 'ops_alert', IncidentNotification::FAILED, 'no_operators');

            return;
        }

        $this->notifyUsers($incident, $operators->all(), 'operations', 'ops_'.$reason, new SafetyIncidentAlertNotification($incident, $reason));
    }

    /**
     * Escalation to a configured level: in-app to its user ids, email to its addresses. A level
     * with nobody configured is recorded as such, not silently passed.
     */
    public function notifyEscalationLevel(SafetyIncident $incident, int $level): void
    {
        $config = (array) config("ehail_safety.escalation.levels.{$level}", []);
        $users = User::query()->whereIn('id', (array) ($config['user_ids'] ?? []))->orderBy('id')->get();
        $emails = (array) ($config['emails'] ?? []);
        $label = (string) ($config['label'] ?? "Level {$level}");

        if ($users->isEmpty() && $emails === []) {
            $this->record($incident, 'none', 'escalation', null, "{$label} (nobody configured)", 'escalation', IncidentNotification::SKIPPED, 'no_recipients_configured');

            return;
        }

        if ($users->isNotEmpty()) {
            $this->notifyUsers($incident, $users->all(), 'escalation', 'escalation', new SafetyIncidentAlertNotification($incident, 'escalated'));
        }

        foreach ($emails as $email) {
            [$local, $domain] = array_pad(explode('@', (string) $email, 2), 2, '');
            $row = $this->record($incident, 'mail', 'escalation', null, "Email to {$label} (".mb_substr($local, 0, 1)."***@{$domain})", 'escalation', IncidentNotification::PENDING);
            $result = $this->mail->send((string) $email, new IncidentEscalationMail(
                $incident->reference,
                $incident->kind->label(),
                $incident->severity->value,
                $level,
                $label,
                (int) $incident->created_at->diffInMinutes(now()),
                route('safety.ops.incidents.show', $incident),
            ));
            $this->finish($row, $result['status'], $result['error']);
        }
    }

    /** @return list<array{key: string, label: string, number: string, tel: string}> */
    public function emergencyNumbers(?string $market = null): array
    {
        $market ??= (string) config('ehail_safety.default_market', 'ZA');

        return array_map(
            fn (array $n) => $n + ['tel' => 'tel:'.$n['number']],
            (array) config("ehail_safety.emergency_numbers.{$market}", []),
        );
    }

    /**
     * @param  list<User>  $users
     */
    private function notifyUsers(SafetyIncident $incident, array $users, string $kind, string $purpose, SafetyIncidentAlertNotification $notification): void
    {
        $delivered = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                $user->notify($notification);
                $delivered++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Safety in-app alert failed.', ['incident_id' => $incident->id, 'user_id' => $user->id, 'error' => class_basename($e)]);
            }
        }

        $who = $kind === 'operations' ? 'operations team' : 'on-call escalation';

        if ($delivered > 0) {
            $this->record($incident, 'database', $kind, null, "In-app alert to {$delivered} {$who} ".Str::plural('member', $delivered), $purpose, IncidentNotification::SENT);
        }

        if ($failed > 0) {
            $this->record($incident, 'database', $kind, null, "In-app alert to {$failed} {$who} ".Str::plural('member', $failed), $purpose, IncidentNotification::FAILED, 'notification_error');
        }

        if ($this->notify->enabled()) {
            $this->record($incident, 'notify', $kind, null, "Dot.Notify email to {$who}", $purpose, IncidentNotification::QUEUED, 'delivery_not_visible_to_ehail');
        }
    }

    private function record(SafetyIncident $incident, string $channel, string $kind, ?int $recipientId, string $label, string $purpose, string $status, ?string $error = null): IncidentNotification
    {
        $row = IncidentNotification::create([
            'safety_incident_id' => $incident->id,
            'channel' => $channel,
            'recipient_kind' => $kind,
            'recipient_id' => $recipientId,
            'recipient_label' => mb_substr($label, 0, 120),
            'purpose' => $purpose,
            'status' => $status,
            'error' => $error,
            'attempted_at' => $status === IncidentNotification::PENDING ? null : now(),
            'sent_at' => $status === IncidentNotification::SENT ? now() : null,
        ]);

        if ($status !== IncidentNotification::PENDING) {
            $this->timeline->add($incident, null, 'notification_'.$status, ['notification_id' => $row->id, 'channel' => $channel, 'recipient' => $row->recipient_label, 'error' => $error]);
        }

        return $row;
    }

    private function finish(IncidentNotification $row, string $status, ?string $error, ?string $providerId = null): void
    {
        $row->forceFill([
            'status' => $status,
            'error' => $error,
            'provider_message_id' => $providerId,
            'attempted_at' => now(),
            'sent_at' => $status === IncidentNotification::SENT ? now() : null,
        ])->save();

        $this->timeline->add($row->incident, null, 'notification_'.$status, ['notification_id' => $row->id, 'channel' => $row->channel, 'recipient' => $row->recipient_label, 'error' => $error]);
    }
}
