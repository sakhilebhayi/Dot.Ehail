<?php

namespace App\Domain\Verification\Models;

use App\Domain\Verification\DocumentStatus;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One uploaded verification document (docs/R46_SAFETY.md). Status changes only through
 * App\Domain\Verification\VerificationService, which writes a VerificationReview row for each.
 * The file lives on a private disk and is only streamed through VerificationDocumentController
 * after the policy check; every view by someone other than the owner is audit-logged.
 *
 * @property DocumentStatus $status
 */
class VerificationDocument extends Model
{
    public const SUBJECT_DRIVER = 'driver';

    public const SUBJECT_VEHICLE = 'vehicle';

    protected $guarded = ['id'];

    protected $hidden = ['disk', 'file_path', 'sha256'];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'expires_on' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'warned_30_at' => 'datetime',
            'warned_7_at' => 'datetime',
            'expired_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class)->withoutGlobalScope('user');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function assignedReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reviewer_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(VerificationReview::class)->orderBy('id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', '!=', DocumentStatus::Superseded->value);
    }

    public function isExpiredOn(\DateTimeInterface $day): bool
    {
        return $this->expires_on !== null && $this->expires_on->lt(Carbon::instance($day)->startOfDay());
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /**
     * What an API client may see about this document. Never the path, disk or hash.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'subject' => $this->subject,
            'vehicle_id' => $this->vehicle_id,
            'status' => $this->status->value,
            'mime' => $this->mime,
            'original_filename' => $this->original_filename,
            'expires_on' => $this->expires_on?->toDateString(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reject_reason' => $this->reject_reason,
            'reject_reason_label' => $this->reject_reason ? config('ehail_safety.verification.reject_reasons.'.$this->reject_reason) : null,
            'review_note' => $this->review_note,
        ];
    }
}
