<?php

namespace App\Domain\Business\Models;

use App\Models\Ride;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The business side of one ride: who booked it, for whom, why, which cost centre, and the money held
 * against the limits until the ride is charged (or released when it ends without a charge).
 */
class BusinessTrip extends Model
{
    public const PREPARED = 'prepared';

    public const BOOKED = 'booked';

    public const CHARGED = 'charged';

    public const RELEASED = 'released';

    protected $guarded = ['id'];

    protected $hidden = ['rider_phone'];

    protected function casts(): array
    {
        return [
            'rider_phone' => 'encrypted',
            'is_guest' => 'boolean',
            'estimate_minor' => 'integer',
            'reserved_minor' => 'integer',
            'charged_minor' => 'integer',
            'policy_snapshot' => 'array',
            'booked_at' => 'datetime',
            'released_at' => 'datetime',
            'rider_phone_purged_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function booker(): BelongsTo
    {
        return $this->belongsTo(BusinessMember::class, 'booker_member_id');
    }

    public function traveller(): BelongsTo
    {
        return $this->belongsTo(BusinessMember::class, 'traveller_member_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(BusinessDepartment::class, 'department_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(BusinessCharge::class);
    }

    /** The member the trip counts against (allowance, "my trips"). */
    public function travellerMemberId(): int
    {
        return (int) ($this->traveller_member_id ?? $this->booker_member_id);
    }

    public function isOnBehalf(): bool
    {
        return $this->is_guest || ($this->traveller_member_id !== null && (int) $this->traveller_member_id !== (int) $this->booker_member_id);
    }

    public function riderLabel(): string
    {
        if ($this->is_guest) {
            return ($this->rider_name ?? 'Guest').' (guest)';
        }

        return ($this->traveller ?? $this->booker)?->displayName() ?? 'Unknown';
    }

    /** "+27 •• ••• 4567": the only way a rider's phone is shown in the portal. */
    public function maskedRiderPhone(): ?string
    {
        $phone = $this->rider_phone;

        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return strlen($digits) < 4 ? '••••' : '•••• '.substr($digits, -4);
    }
}
