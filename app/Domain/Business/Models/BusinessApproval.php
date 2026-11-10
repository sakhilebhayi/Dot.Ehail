<?php

namespace App\Domain\Business\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ride that needs an approver's yes before it can be booked on the account. Pending requests expire
 * at decide_by; an approved request must be booked before use_by and matches one booking only.
 */
class BusinessApproval extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const USED = 'used';

    public const WITHDRAWN = 'withdrawn';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'estimate_minor' => 'integer',
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'dropoff_lat' => 'float',
            'dropoff_lng' => 'float',
            'trip_at' => 'datetime',
            'decided_at' => 'datetime',
            'decide_by' => 'datetime',
            'use_by' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(BusinessMember::class, 'requester_member_id');
    }

    public function traveller(): BelongsTo
    {
        return $this->belongsTo(BusinessMember::class, 'traveller_member_id');
    }

    public function riderLabel(): string
    {
        if ($this->guest_name) {
            return $this->guest_name.' (guest)';
        }

        return ($this->traveller ?? $this->requester)?->displayName() ?? 'Unknown';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => 'Waiting for a decision',
            self::APPROVED => 'Approved',
            self::DECLINED => 'Declined',
            self::EXPIRED => 'Expired',
            self::USED => 'Booked',
            self::WITHDRAWN => 'Withdrawn',
            default => ucfirst((string) $this->status),
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::APPROVED, self::USED => 'go',
            self::PENDING => 'caution',
            default => 'neutral',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'rider' => $this->riderLabel(),
            'category' => $this->category_code,
            'pickup' => $this->pickup_address,
            'dropoff' => $this->dropoff_address,
            'estimate_minor' => $this->estimate_minor,
            'currency' => $this->currency,
            'purpose' => $this->purpose,
            'reasons' => $this->reasons ?? [],
            'decide_by' => $this->decide_by?->toIso8601String(),
            'use_by' => $this->use_by?->toIso8601String(),
            'decision_note' => $this->decision_note,
        ];
    }
}
