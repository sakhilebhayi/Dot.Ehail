<?php

namespace App\Domain\Safety\Models;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private report about another user (never shown to them, separate from public ratings), or a
 * system flag (reporter_id null) such as an abusive cancellation pattern. Reviewed by operations;
 * nothing here suspends anyone by itself.
 */
class UserReport extends Model
{
    public const CATEGORIES = [
        'unsafe_driving', 'harassment', 'abusive_language', 'discrimination', 'intoxication',
        'vehicle_mismatch', 'driver_mismatch', 'damage', 'fraud', 'threatening_behaviour', 'other',
    ];

    public const SYSTEM_CATEGORIES = ['cancellation_pattern'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reportedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }
}
