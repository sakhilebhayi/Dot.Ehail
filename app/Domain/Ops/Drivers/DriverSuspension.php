<?php

namespace App\Domain\Ops\Drivers;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A suspension of a driver account, with its reason, who decided it, how it ended and the driver's appeal.
 * appeal_status: none | open | upheld | rejected.
 *
 * @property int $id
 * @property int $driver_profile_id
 * @property int $user_id
 * @property string $reason_code
 * @property string|null $note
 * @property string $previous_status
 * @property Carbon $suspended_at
 * @property Carbon|null $lifted_at
 * @property string $appeal_status
 */
class DriverSuspension extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'suspended_at' => 'datetime',
        'lifted_at' => 'datetime',
        'appealed_at' => 'datetime',
        'appeal_decided_at' => 'datetime',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id')->withoutGlobalScope('user');
    }

    public function suspender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    public function lifter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null;
    }

    public function reasonLabel(): string
    {
        return SuspensionService::REASONS[$this->reason_code] ?? $this->reason_code;
    }
}
