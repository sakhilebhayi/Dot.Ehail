<?php

namespace App\Domain\Support;

use App\Domain\Safety\IncidentKind;
use App\Domain\Safety\IncidentService;
use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Safety\Notifications\SafetyUpdateNotification;
use App\Domain\Safety\RideSafety;
use App\Domain\Safety\SafetyActionRefused;
use App\Domain\Support\Models\SupportCase;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Support\Seams\FareAdjustmentRequest;
use App\Domain\Verification\Files\SecureFileStore;
use App\Domain\Verification\Files\StoredFile;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\RidePayment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Support cases: open, reply (with attachments on the private safety-files disk), triage,
 * assign, change status, escalate, resolve with a code, and SLA timers. A lost-property case brings
 * the other party of the ride in as the counterparty; messages between them are relayed with
 * phone numbers and emails masked. A fare dispute asks the pricing seam for a review.
 */
class SupportCaseService
{
    public const RESOLUTION_CODES = [
        'answered' => 'Question answered',
        'fare_adjusted' => 'Fare adjusted',
        'refund_requested' => 'Refund requested from payments',
        'no_change' => 'Reviewed, no change',
        'item_returned' => 'Lost item returned',
        'item_not_found' => 'Lost item not found',
        'warning_issued' => 'Warning issued',
        'account_actioned' => 'Account action taken',
        'duplicate' => 'Duplicate case',
        'no_response' => 'No response from the user',
        'other' => 'Other (see note)',
    ];

    public function __construct(
        private readonly SecureFileStore $files,
        private readonly ContactDetailRedactor $redactor,
        private readonly FareAdjustmentRequest $fareReview,
        private readonly IncidentService $incidents,
    ) {}

    /**
     * @param  array{ride_id?: ?int, ride_payment_id?: ?int, safety_incident_id?: ?int, requested_amount_minor?: ?int}  $links
     */
    public function open(User $user, SupportCategory $category, string $subject, string $body, array $links = [], ?UploadedFile $attachment = null): SupportCase
    {
        $ride = isset($links['ride_id']) ? Ride::find($links['ride_id']) : null;
        $role = $ride ? RideSafety::roleOf($ride, $user) : null;

        if (isset($links['ride_id']) && ($ride === null || $role === null)) {
            throw ValidationException::withMessages(['ride_id' => 'Choose one of your own rides.']);
        }

        if ($category->needsRide() && $ride === null) {
            throw ValidationException::withMessages(['ride_id' => 'Choose the ride this is about.']);
        }

        if ($category === SupportCategory::DriverConduct && $role !== 'passenger') {
            throw ValidationException::withMessages(['category' => 'Driver conduct cases are opened by the passenger of the ride.']);
        }

        if ($category === SupportCategory::PassengerConduct && $role !== 'driver') {
            throw ValidationException::withMessages(['category' => 'Passenger conduct cases are opened by the driver of the ride.']);
        }

        $payment = null;

        if (isset($links['ride_payment_id'])) {
            $payment = RidePayment::query()->find($links['ride_payment_id']);

            if ($payment === null || $ride === null || (int) $payment->ride_id !== (int) $ride->id || $role !== 'passenger') {
                throw ValidationException::withMessages(['ride_payment_id' => 'Choose a payment for this ride.']);
            }
        }

        $incident = null;

        if (isset($links['safety_incident_id'])) {
            $incident = SafetyIncident::query()->find($links['safety_incident_id']);

            if ($incident === null || $incident->reporter_id !== $user->id) {
                throw ValidationException::withMessages(['safety_incident_id' => 'Choose one of your own safety reports.']);
            }
        }

        $counterparty = $category === SupportCategory::LostProperty && $ride ? RideSafety::otherParty($ride, $user) : null;

        if ($category === SupportCategory::LostProperty && $counterparty === null) {
            throw ValidationException::withMessages(['ride_id' => 'Lost property needs a ride with a driver.']);
        }

        $priority = $category->defaultPriority();

        $case = DB::transaction(function () use ($user, $category, $subject, $body, $ride, $role, $payment, $incident, $counterparty, $priority) {
            $case = SupportCase::create([
                'reference' => $this->newReference(),
                'user_id' => $user->id,
                'requester_role' => $role ?? 'user',
                'category' => $category,
                'status' => SupportStatus::New,
                'priority' => $priority,
                'subject' => mb_substr(trim($subject), 0, 160),
                'ride_id' => $ride?->id,
                'ride_payment_id' => $payment?->id,
                'safety_incident_id' => $incident?->id,
                'counterparty_user_id' => $counterparty,
                'first_response_due_at' => now()->addMinutes($priority->firstResponseMinutes()),
                'resolution_due_at' => now()->addMinutes($priority->resolutionMinutes()),
            ]);

            $this->addMessage($case, $user, 'requester', $category === SupportCategory::LostProperty ? 'participants' : 'requester', $body, null);

            return $case;
        });

        if ($attachment !== null) {
            $this->attach($case, $user, 'requester', $case->category === SupportCategory::LostProperty ? 'participants' : 'requester', 'Attachment', $attachment);
        }

        $this->afterOpen($case, $user, $ride, $links);

        return $case->fresh();
    }

    public function reply(SupportCase $case, User $author, string $body, ?UploadedFile $attachment = null, string $visibility = 'participants', bool $awaitUser = false): SupportMessage
    {
        $role = $this->roleOf($case, $author);

        if ($role === null) {
            throw new SafetyActionRefused('You are not part of this case.');
        }

        if ($case->status === SupportStatus::Closed) {
            throw new SafetyActionRefused('This case is closed. Open a new one if you still need help.');
        }

        $visibility = match ($role) {
            'agent' => in_array($visibility, ['participants', 'requester', 'internal'], true) ? $visibility : 'participants',
            'counterparty' => 'participants',
            default => $case->category === SupportCategory::LostProperty ? 'participants' : 'requester',
        };

        if ($role === 'agent' && $visibility === 'participants' && $case->counterparty_user_id === null) {
            $visibility = 'requester';
        }

        $message = $attachment !== null
            ? $this->attach($case, $author, $role, $visibility, $body, $attachment)
            : $this->addMessage($case, $author, $role, $visibility, $body, null);

        DB::transaction(function () use ($case, $role, $visibility, $awaitUser) {
            $locked = SupportCase::query()->lockForUpdate()->findOrFail($case->id);

            if ($role === 'agent' && $visibility !== 'internal') {
                $locked->first_responded_at ??= now();

                if ($awaitUser && $locked->status->canBecome(SupportStatus::WaitingOnUser)) {
                    $locked->status = SupportStatus::WaitingOnUser;
                } elseif (in_array($locked->status, [SupportStatus::New, SupportStatus::Triaged], true)) {
                    $locked->status = SupportStatus::InProgress;
                }
            }

            if ($role === 'requester' && in_array($locked->status, [SupportStatus::WaitingOnUser, SupportStatus::Resolved], true)) {
                $locked->status = SupportStatus::InProgress;
                $locked->resolved_at = null;
            }

            $locked->save();
        });

        if ($role === 'agent' && $visibility !== 'internal') {
            $this->tell($case->user_id, $case, 'support_reply', 'New reply on your support case', "{$case->reference}: operations replied.");
        }

        if ($role !== 'counterparty' && $visibility === 'participants' && $case->counterparty_user_id !== null && $author->id !== $case->counterparty_user_id) {
            $this->tell($case->counterparty_user_id, $case, 'support_relay', 'Message about a lost item', "{$case->reference}: you have a new message about an item left after a trip.");
        }

        if ($role === 'counterparty') {
            $this->tell($case->user_id, $case, 'support_relay', 'Reply about your lost item', "{$case->reference}: the other person on the trip replied.");
        }

        return $message;
    }

    public function transition(SupportCase $case, User $operator, SupportStatus $to, ?string $resolutionCode = null, ?string $note = null): SupportCase
    {
        if ($to === SupportStatus::Resolved && ! array_key_exists((string) $resolutionCode, self::RESOLUTION_CODES)) {
            throw ValidationException::withMessages(['resolution_code' => 'Choose a resolution code.']);
        }

        if ($resolutionCode === 'other' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['resolution_note' => 'Explain the resolution in the note.']);
        }

        $updated = DB::transaction(function () use ($case, $operator, $to, $resolutionCode, $note) {
            $locked = SupportCase::query()->lockForUpdate()->findOrFail($case->id);
            $from = $locked->status;

            if (! $from->canBecome($to)) {
                throw new SafetyActionRefused("A {$from->value} case cannot become {$to->value}.");
            }

            $locked->status = $to;
            $locked->owner_id ??= $operator->id;

            if ($to === SupportStatus::Resolved) {
                $locked->resolution_code = $resolutionCode;
                $locked->resolution_note = $note;
                $locked->resolved_at = now();
            } elseif ($to === SupportStatus::Closed) {
                $locked->closed_at = now();
            } elseif ($from === SupportStatus::Resolved) {
                $locked->resolved_at = null;
            }

            $locked->save();
            $this->addMessage($locked, null, 'system', 'internal', "Status {$from->value} -> {$to->value}".($resolutionCode ? " ({$resolutionCode})" : '').'.', null);
            AuditLog::record('support_case.'.$to->value, $operator, $locked, ['from' => $from->value]);

            return $locked;
        });

        if (in_array($to, [SupportStatus::Resolved, SupportStatus::WaitingOnUser], true)) {
            $this->tell($updated->user_id, $updated, 'support_status', $to === SupportStatus::Resolved ? 'Support case resolved' : 'We need something from you', "{$updated->reference}: ".($to === SupportStatus::Resolved ? self::RESOLUTION_CODES[$resolutionCode] : 'please reply to the case.'));
        }

        return $updated;
    }

    public function assign(SupportCase $case, User $owner, User $actor): SupportCase
    {
        if (! $owner->isPlatformOperator()) {
            throw new SafetyActionRefused('Cases can only be assigned to operators.');
        }

        $case->forceFill(['owner_id' => $owner->id])->save();
        $this->addMessage($case, null, 'system', 'internal', 'Assigned to '.($owner->name ?: 'user #'.$owner->id).' by '.($actor->name ?: 'user #'.$actor->id).'.', null);

        return $case;
    }

    public function setPriority(SupportCase $case, SupportPriority $priority, User $actor): SupportCase
    {
        $case->forceFill([
            'priority' => $priority,
            'first_response_due_at' => $case->first_responded_at ? $case->first_response_due_at : $case->created_at->copy()->addMinutes($priority->firstResponseMinutes()),
            'resolution_due_at' => $case->created_at->copy()->addMinutes($priority->resolutionMinutes()),
        ])->save();
        $this->addMessage($case, null, 'system', 'internal', 'Priority set to '.$priority->value.' by '.($actor->name ?: 'user #'.$actor->id).'.', null);

        return $case;
    }

    public function escalate(SupportCase $case, ?User $actor, string $reason): SupportCase
    {
        $case->forceFill([
            'escalation_level' => $case->escalation_level + 1,
            'escalated_at' => now(),
            'priority' => $case->priority->higher(),
        ])->save();

        $this->addMessage($case, null, 'system', 'internal', 'Escalated to level '.$case->escalation_level.': '.$reason.'.', null);

        User::query()->where('is_platform_operator', true)->get()->each(fn (User $op) => $this->tell($op->id, $case, 'support_escalated', 'Support case escalated', "{$case->reference}: {$reason}."));

        return $case;
    }

    /** Scheduler: first-response and resolution SLA breaches, each escalated once. */
    public function sweepSla(): int
    {
        $count = 0;
        $open = array_map(fn (SupportStatus $s) => $s->value, array_filter(SupportStatus::cases(), fn (SupportStatus $s) => $s->isOpen()));

        SupportCase::query()->whereIn('status', $open)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('first_responded_at')->where('first_response_due_at', '<=', now()))
                ->orWhere('resolution_due_at', '<=', now()))
            ->orderBy('id')
            ->each(function (SupportCase $case) use (&$count) {
                $meta = (array) $case->meta;
                $breaches = [];

                if ($case->first_responded_at === null && $case->first_response_due_at?->lte(now()) && empty($meta['first_response_breached'])) {
                    $breaches[] = 'first_response';
                }

                if ($case->status !== SupportStatus::WaitingOnUser && $case->resolution_due_at?->lte(now()) && empty($meta['resolution_breached'])) {
                    $breaches[] = 'resolution';
                }

                if ($breaches === []) {
                    return;
                }

                foreach ($breaches as $breach) {
                    $meta[$breach.'_breached'] = now()->toIso8601String();
                }

                $case->forceFill(['meta' => $meta, 'sla_breached_at' => $case->sla_breached_at ?? now()])->save();
                $this->escalate($case, null, 'SLA breached ('.implode(', ', $breaches).')');
                $count++;
            });

        return $count;
    }

    /** requester | counterparty | agent | null */
    public function roleOf(SupportCase $case, User $user): ?string
    {
        return match (true) {
            $user->id === $case->user_id => 'requester',
            $case->counterparty_user_id !== null && $user->id === $case->counterparty_user_id => 'counterparty',
            $user->isPlatformOperator() => 'agent',
            default => null,
        };
    }

    /** @return list<string> */
    public function visibleTo(SupportCase $case, User $user): array
    {
        return match ($this->roleOf($case, $user)) {
            'agent' => ['participants', 'requester', 'internal'],
            'requester' => ['participants', 'requester'],
            'counterparty' => ['participants'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $links
     */
    private function afterOpen(SupportCase $case, User $user, ?Ride $ride, array $links): void
    {
        if ($case->category === SupportCategory::SafetyIncident && $case->safety_incident_id === null) {
            try {
                $incident = $this->incidents->raise($ride, $user, IncidentKind::Other, [
                    'source' => 'support_case',
                    'description' => 'Opened as support case '.$case->reference.': '.$case->subject,
                    'notify_contacts' => false,
                ])['incident'];
                $case->forceFill(['safety_incident_id' => $incident->id])->save();
                $this->addMessage($case, null, 'system', 'internal', "Safety incident {$incident->reference} raised for the safety team.", null);
            } catch (Throwable $e) {
                Log::error('Could not raise an incident for a safety support case.', ['case_id' => $case->id, 'error' => class_basename($e)]);
            }
        }

        if ($case->category === SupportCategory::FareDispute && $ride !== null) {
            $result = $this->fareReview->request($ride, $case, $user, (string) $case->subject, isset($links['requested_amount_minor']) ? (int) $links['requested_amount_minor'] : null);
            $this->addMessage($case, null, 'system', 'requester', $result['message'], null);
        }

        if ($case->priority === SupportPriority::Urgent) {
            User::query()->where('is_platform_operator', true)->get()
                ->each(fn (User $op) => $this->tell($op->id, $case, 'support_urgent', 'Urgent support case', "{$case->reference}: {$case->category->label()}."));
        }

        if ($case->counterparty_user_id !== null) {
            $this->tell($case->counterparty_user_id, $case, 'support_relay', 'An item may have been left after a trip', "{$case->reference}: the other person on a recent trip is looking for something they left. Reply through Dot.Ehail; your number is never shared.");
        }
    }

    private function attach(SupportCase $case, User $author, string $role, string $visibility, string $body, UploadedFile $file): SupportMessage
    {
        $stored = $this->files->store($file, (string) config('ehail_safety.uploads.support_disk'), 'support/'.$case->id, ['image', 'pdf'], 'attachment');

        return $this->addMessage($case, $author, $role, $visibility, $body, $stored);
    }

    private function addMessage(SupportCase $case, ?User $author, string $role, string $visibility, string $body, ?StoredFile $file): SupportMessage
    {
        $body = trim($body);

        if ($case->counterparty_user_id !== null && in_array($role, ['requester', 'counterparty'], true) && $visibility === 'participants') {
            $body = $this->redactor->redact($body);
        }

        return SupportMessage::create([
            'support_case_id' => $case->id,
            'author_id' => $author?->id,
            'author_role' => $role,
            'visibility' => $visibility,
            'body' => mb_substr($body !== '' ? $body : '(attachment)', 0, 5000),
            'attachment_disk' => $file?->disk,
            'attachment_path' => $file?->path,
            'attachment_name' => $file?->originalName,
            'attachment_mime' => $file?->mime,
            'attachment_size' => $file?->size,
        ]);
    }

    private function tell(?int $userId, SupportCase $case, string $type, string $title, string $message): void
    {
        try {
            User::find($userId)?->notify(new SafetyUpdateNotification($type, $title, $message, ['support_case_id' => $case->id]));
        } catch (Throwable $e) {
            Log::warning('Support notification failed.', ['case_id' => $case->id, 'error' => class_basename($e)]);
        }
    }

    private function newReference(): string
    {
        do {
            $reference = 'SUP-'.Str::upper(Str::random(8));
        } while (SupportCase::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
