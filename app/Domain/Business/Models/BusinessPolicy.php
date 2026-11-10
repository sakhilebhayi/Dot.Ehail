<?php

namespace App\Domain\Business\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ride policy. Hard rules refuse a ride (categories, days, hours, service areas, maximum fare, purpose);
 * approval_above_minor sends a ride above that fare to an approver first.
 */
class BusinessPolicy extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'allowed_categories' => 'array',
            'allowed_days' => 'array',
            'service_area_ids' => 'array',
            'hours_start' => 'integer',
            'hours_end' => 'integer',
            'max_fare_minor' => 'integer',
            'purpose_required' => 'boolean',
            'approval_above_minor' => 'integer',
            'approval_window_minutes' => 'integer',
            'guest_bookings' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    public function hasHours(): bool
    {
        return $this->hours_start !== null && $this->hours_end !== null;
    }

    public static function minutesLabel(?int $minutes): string
    {
        return $minutes === null ? '' : sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'allowed_categories' => $this->allowed_categories,
            'allowed_days' => $this->allowed_days,
            'hours' => $this->hasHours() ? ['from' => self::minutesLabel($this->hours_start), 'to' => self::minutesLabel($this->hours_end)] : null,
            'max_fare_minor' => $this->max_fare_minor,
            'service_area_ids' => $this->service_area_ids,
            'purpose_required' => $this->purpose_required,
            'approval_above_minor' => $this->approval_above_minor,
            'approval_window_minutes' => $this->approval_window_minutes,
            'guest_bookings' => $this->guest_bookings,
        ];
    }
}
