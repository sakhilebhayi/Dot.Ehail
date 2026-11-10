<?php

namespace App\Domain\Verification;

use App\Domain\Verification\Files\SecureFileStore;
use App\Domain\Verification\Models\VerificationDocument;
use App\Domain\Verification\Models\VerificationReview;
use App\Domain\Verification\Notifications\VerificationDocumentExpiringNotification;
use App\Domain\Verification\Notifications\VerificationDocumentReviewedNotification;
use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Submit, review (assign, approve, reject, request resubmission), expire and report on driver
 * and vehicle documents. Every state change runs under a row lock, is checked against
 * DocumentStatus::allowedNext() and leaves a VerificationReview row.
 */
class VerificationService
{
    public function __construct(
        private readonly DocumentRequirements $requirements,
        private readonly SecureFileStore $files,
    ) {}

    /**
     * @throws ValidationException
     */
    public function submit(User $driver, string $type, UploadedFile $file, ?Vehicle $vehicle = null, ?string $expiresOn = null): VerificationDocument
    {
        $profile = $this->profileOf($driver);
        $market = $this->requirements->defaultMarket();

        if (! $this->requirements->exists($type, $market)) {
            throw ValidationException::withMessages(['type' => 'Unknown document type.']);
        }

        $rule = $this->requirements->type($type, $market);

        if ($rule['subject'] === VerificationDocument::SUBJECT_VEHICLE) {
            if ($vehicle === null || $vehicle->driver_profile_id !== $profile->id) {
                throw ValidationException::withMessages(['vehicle_id' => 'Choose one of your own vehicles for this document.']);
            }
        } elseif ($vehicle !== null) {
            throw ValidationException::withMessages(['vehicle_id' => 'This document belongs to you, not to a vehicle.']);
        }

        $expiry = $this->parseExpiry($expiresOn, (bool) $rule['expiry_required']);
        $stored = $this->files->store($file, (string) config('ehail_safety.uploads.disk'), 'verification/'.$profile->id, $rule['kinds'], 'document');

        return DB::transaction(function () use ($driver, $profile, $vehicle, $type, $rule, $market, $stored, $expiry) {
            $document = VerificationDocument::create([
                'user_id' => $driver->id,
                'driver_profile_id' => $profile->id,
                'vehicle_id' => $rule['subject'] === VerificationDocument::SUBJECT_VEHICLE ? $vehicle?->id : null,
                'market' => $market,
                'subject' => $rule['subject'],
                'type' => $type,
                'status' => DocumentStatus::Pending,
                'disk' => $stored->disk,
                'file_path' => $stored->path,
                'original_filename' => $stored->originalName,
                'mime' => $stored->mime,
                'size_bytes' => $stored->size,
                'sha256' => $stored->sha256,
                'expires_on' => $expiry?->toDateString(),
                'submitted_at' => now(),
            ]);

            $this->history($document, $driver, 'submitted', null, DocumentStatus::Pending);

            // Earlier uploads for the same slot that were never approved are replaced at once. An
            // approved one stays in force until this one is approved (a renewal never takes the
            // driver offline while it waits for review).
            $this->slotQuery($document)
                ->whereKeyNot($document->id)
                ->whereIn('status', [DocumentStatus::Pending->value, DocumentStatus::Rejected->value, DocumentStatus::ResubmissionRequested->value])
                ->lockForUpdate()
                ->get()
                ->each(fn (VerificationDocument $old) => $this->supersede($old, $document, $driver));

            return $document;
        });
    }

    public function assign(VerificationDocument $document, User $reviewer, User $actor): VerificationDocument
    {
        if (! $reviewer->isPlatformOperator()) {
            throw new InvalidVerificationAction('Documents can only be assigned to operations reviewers.');
        }

        return DB::transaction(function () use ($document, $reviewer, $actor) {
            $locked = VerificationDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($locked->status !== DocumentStatus::Pending) {
                throw new InvalidVerificationAction('Only a document waiting for review can be assigned.');
            }

            $locked->forceFill(['assigned_reviewer_id' => $reviewer->id])->save();
            $this->history($locked, $actor, 'assigned', $locked->status, $locked->status, null, 'Assigned to user #'.$reviewer->id);

            return $locked;
        });
    }

    public function approve(VerificationDocument $document, User $reviewer, ?string $expiresOn = null, ?string $note = null): VerificationDocument
    {
        return $this->decide($document, $reviewer, DocumentStatus::Approved, null, $note, function (VerificationDocument $locked) use ($expiresOn) {
            $rule = $this->requirements->type($locked->type, $locked->market);

            if ($expiresOn !== null) {
                $locked->expires_on = $this->parseExpiry($expiresOn, false);
            }

            if ($rule['expiry_required'] && $locked->expires_on === null) {
                throw new InvalidVerificationAction('Enter the expiry date shown on the document before approving it.');
            }

            if ($locked->expires_on !== null && $locked->expires_on->lt(today())) {
                throw new InvalidVerificationAction('This document has already expired; reject it with the reason "expired".');
            }
        });
    }

    public function reject(VerificationDocument $document, User $reviewer, string $reason, ?string $note = null): VerificationDocument
    {
        return $this->decide($document, $reviewer, DocumentStatus::Rejected, $reason, $note);
    }

    public function requestResubmission(VerificationDocument $document, User $reviewer, string $reason, ?string $note = null): VerificationDocument
    {
        return $this->decide($document, $reviewer, DocumentStatus::ResubmissionRequested, $reason, $note);
    }

    /**
     * Approved documents whose expiry date has passed become expired; a driver with an expired
     * mandatory document is taken offline (driver_profiles.is_online) and told. Idempotent.
     */
    public function expireDue(): int
    {
        $count = 0;

        VerificationDocument::query()
            ->where('status', DocumentStatus::Approved->value)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', today()->toDateString())
            ->orderBy('id')
            ->each(function (VerificationDocument $document) use (&$count) {
                $changed = DB::transaction(function () use ($document) {
                    $locked = VerificationDocument::query()->lockForUpdate()->find($document->id);

                    if ($locked === null || $locked->status !== DocumentStatus::Approved) {
                        return null;
                    }

                    $locked->forceFill(['status' => DocumentStatus::Expired, 'expired_at' => now()])->save();
                    $this->history($locked, null, 'expired', DocumentStatus::Approved, DocumentStatus::Expired);

                    return $locked;
                });

                if ($changed === null) {
                    return;
                }

                $count++;
                $this->takeOfflineIfIneligible($changed);
                $this->notifyOwner($changed, new VerificationDocumentExpiringNotification($changed, 0));
            });

        return $count;
    }

    /**
     * Warn the owner 30 and 7 days (config) before an approved document expires. Each warning
     * is sent once per document.
     */
    public function warnExpiring(): int
    {
        $sent = 0;

        foreach ((array) config('ehail_safety.verification.expiry_warning_days', [30, 7]) as $days) {
            $column = $days <= 7 ? 'warned_7_at' : 'warned_30_at';

            VerificationDocument::query()
                ->where('status', DocumentStatus::Approved->value)
                ->whereNull($column)
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '>=', today()->toDateString())
                ->whereDate('expires_on', '<=', today()->addDays((int) $days)->toDateString())
                ->orderBy('id')
                ->each(function (VerificationDocument $document) use ($column, $days, &$sent) {
                    $claimed = VerificationDocument::query()->whereKey($document->id)->whereNull($column)->update([$column => now()]);

                    if ($claimed === 0) {
                        return;
                    }

                    $this->history($document, null, 'expiry_warning', $document->status, $document->status, null, "Expires within {$days} days");
                    $this->notifyOwner($document, new VerificationDocumentExpiringNotification($document, (int) $days));
                    $sent++;
                });
        }

        return $sent;
    }

    /**
     * @return array{status: string, items: list<array<string, mixed>>}
     */
    public function driverStatus(DriverProfile $profile): array
    {
        return $this->summarise($this->requirements->required(VerificationDocument::SUBJECT_DRIVER, null), $profile->id, null);
    }

    /**
     * @return array{status: string, items: list<array<string, mixed>>}
     */
    public function vehicleStatus(Vehicle $vehicle): array
    {
        return $this->summarise($this->requirements->required(VerificationDocument::SUBJECT_VEHICLE, $vehicle->type), $vehicle->driver_profile_id, $vehicle->id);
    }

    /** Record a view of the file by anyone other than its owner (POPIA: who saw an ID document). */
    public function recordView(VerificationDocument $document, User $viewer): void
    {
        if ($viewer->id === $document->user_id) {
            return;
        }

        AuditLog::record('verification_document.viewed', $viewer, $document, [
            'type' => $document->type,
            'as' => $viewer->isPlatformOperator() ? 'operator' : 'assigned_reviewer',
        ]);
    }

    /**
     * @param  array<string, array{label: string, expiry_required: bool, max_age_days: ?int}>  $required
     * @return array{status: string, items: list<array<string, mixed>>}
     */
    private function summarise(array $required, int $profileId, ?int $vehicleId): array
    {
        $documents = VerificationDocument::query()
            ->current()
            ->where('driver_profile_id', $profileId)
            ->when($vehicleId === null, fn ($q) => $q->whereNull('vehicle_id'), fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->orderByDesc('id')
            ->get()
            ->groupBy('type');

        $items = [];
        $pending = false;
        $blocked = false;

        foreach ($required as $type => $rule) {
            $state = $this->slotState($documents->get($type, collect())->all(), $rule);
            $items[] = ['type' => $type, 'label' => $rule['label']] + $state;
            $blocked = $blocked || ! $state['satisfied'];
            $pending = $pending || $state['state'] === 'pending_review';
        }

        return [
            'status' => ! $blocked ? 'verified' : ($pending ? 'pending_review' : 'action_required'),
            'items' => $items,
        ];
    }

    /**
     * @param  list<VerificationDocument>  $docs  current documents for one slot, newest first
     * @param  array{label: string, expiry_required: bool, max_age_days: ?int}  $rule
     * @return array{satisfied: bool, state: string, document_id: ?int, expires_on: ?string, expiring_soon: bool, renewal_pending: bool}
     */
    private function slotState(array $docs, array $rule): array
    {
        $today = today();
        $valid = null;

        foreach ($docs as $doc) {
            if ($doc->status !== DocumentStatus::Approved || $doc->isExpiredOn($today)) {
                continue;
            }

            if ($rule['max_age_days'] !== null && $doc->submitted_at->lt(now()->subDays($rule['max_age_days']))) {
                continue;
            }

            $valid = $doc;
            break;
        }

        $latest = $docs[0] ?? null;

        if ($valid !== null) {
            return [
                'satisfied' => true,
                'state' => 'approved',
                'document_id' => $valid->id,
                'expires_on' => $valid->expires_on?->toDateString(),
                'expiring_soon' => $valid->expires_on !== null && $valid->expires_on->lte($today->copy()->addDays(30)),
                'renewal_pending' => $latest !== null && $latest->id !== $valid->id && $latest->status === DocumentStatus::Pending,
            ];
        }

        $state = match (true) {
            $latest === null => 'missing',
            $latest->status === DocumentStatus::Pending => 'pending_review',
            $latest->status === DocumentStatus::Rejected => 'rejected',
            $latest->status === DocumentStatus::ResubmissionRequested => 'resubmission_requested',
            $latest->status === DocumentStatus::Expired, $latest->isExpiredOn($today) => 'expired',
            default => 'too_old',
        };

        return [
            'satisfied' => false,
            'state' => $state,
            'document_id' => $latest?->id,
            'expires_on' => $latest?->expires_on?->toDateString(),
            'expiring_soon' => false,
            'renewal_pending' => false,
        ];
    }

    private function decide(VerificationDocument $document, User $reviewer, DocumentStatus $to, ?string $reason, ?string $note, ?callable $guard = null): VerificationDocument
    {
        if (! $reviewer->isPlatformOperator()) {
            throw new InvalidVerificationAction('Only operations reviewers can decide on documents.');
        }

        if ($reviewer->id === $document->user_id) {
            throw new InvalidVerificationAction('You cannot review your own document.');
        }

        if ($to !== DocumentStatus::Approved && ! array_key_exists((string) $reason, (array) config('ehail_safety.verification.reject_reasons'))) {
            throw new InvalidVerificationAction('Choose a reason from the list.');
        }

        if ($reason === 'other' && trim((string) $note) === '') {
            throw new InvalidVerificationAction('Explain the reason in the note.');
        }

        $decided = DB::transaction(function () use ($document, $reviewer, $to, $reason, $note, $guard) {
            $locked = VerificationDocument::query()->lockForUpdate()->findOrFail($document->id);
            $from = $locked->status;

            if (! $from->canBecome($to)) {
                throw new InvalidVerificationAction("A {$from->value} document cannot become {$to->value}.");
            }

            if ($guard !== null) {
                $guard($locked);
            }

            $locked->forceFill([
                'status' => $to,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'reject_reason' => $to === DocumentStatus::Approved ? null : $reason,
                'review_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ])->save();

            $this->history($locked, $reviewer, $to->value, $from, $to, $reason, $note);

            if ($to === DocumentStatus::Approved) {
                $this->slotQuery($locked)
                    ->whereKeyNot($locked->id)
                    ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Expired->value])
                    ->where('id', '<', $locked->id)
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (VerificationDocument $old) => $old->status === DocumentStatus::Approved ? $this->supersede($old, $locked, $reviewer) : null);
            }

            return $locked;
        });

        if ($to !== DocumentStatus::Approved) {
            $this->takeOfflineIfIneligible($decided);
        }

        $this->notifyOwner($decided, new VerificationDocumentReviewedNotification($decided));

        return $decided;
    }

    private function supersede(VerificationDocument $old, VerificationDocument $by, ?User $actor): void
    {
        $from = $old->status;
        $old->forceFill(['status' => DocumentStatus::Superseded, 'superseded_by_id' => $by->id])->save();
        $this->history($old, $actor, 'superseded', $from, DocumentStatus::Superseded, null, 'Replaced by document #'.$by->id);
    }

    /** Same driver, same vehicle (or none), same type. */
    private function slotQuery(VerificationDocument $document): Builder
    {
        return VerificationDocument::query()
            ->where('driver_profile_id', $document->driver_profile_id)
            ->where('type', $document->type)
            ->when($document->vehicle_id === null, fn ($q) => $q->whereNull('vehicle_id'), fn ($q) => $q->where('vehicle_id', $document->vehicle_id));
    }

    private function history(VerificationDocument $document, ?User $actor, string $action, ?DocumentStatus $from, ?DocumentStatus $to, ?string $reason = null, ?string $note = null): void
    {
        VerificationReview::create([
            'verification_document_id' => $document->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'reason' => $reason,
            'note' => $note,
        ]);
    }

    /**
     * An expired or rejected mandatory document must not leave a driver online. Uses the same
     * gate as going online, so the rule lives in one place.
     */
    private function takeOfflineIfIneligible(VerificationDocument $document): void
    {
        $profile = DriverProfile::withoutGlobalScope('user')->find($document->driver_profile_id);

        if ($profile === null || ! $profile->is_online) {
            return;
        }

        $vehicle = $profile->activeVehicle();
        $driver = User::find($profile->user_id);

        if ($driver === null) {
            return;
        }

        $result = $vehicle === null ? null : app(DriverEligibility::class)->canGoOnline($driver, $vehicle);

        if ($result === null || ! $result->allowed()) {
            DriverProfile::withoutGlobalScope('user')->whereKey($profile->id)->update(['is_online' => false]);
            AuditLog::record('driver.forced_offline', null, $profile, ['reason' => 'verification', 'codes' => $result?->codes() ?? ['no_active_vehicle']]);
        }
    }

    private function notifyOwner(VerificationDocument $document, Notification $notification): void
    {
        try {
            User::find($document->user_id)?->notify($notification);
        } catch (Throwable $e) {
            Log::warning('Could not notify a driver about a verification document.', ['document_id' => $document->id, 'error' => class_basename($e)]);
        }
    }

    private function profileOf(User $driver): DriverProfile
    {
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->first();

        if ($profile === null) {
            throw ValidationException::withMessages(['type' => 'Apply to drive first; documents belong to a driver profile.']);
        }

        return $profile;
    }

    private function parseExpiry(?string $value, bool $required): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            if ($required) {
                throw ValidationException::withMessages(['expires_on' => 'Enter the expiry date printed on the document.']);
            }

            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages(['expires_on' => 'Use the format YYYY-MM-DD.']);
        }

        if ($date->lt(today())) {
            throw ValidationException::withMessages(['expires_on' => 'This document has already expired. Upload the current one.']);
        }

        return $date;
    }
}
