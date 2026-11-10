<?php

namespace App\Domain\Earnings\Models;

use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a driver earned from one trip, cancellation fee, incentive award or adjustment.
 *
 * pending    the trip's payment is not settled yet (card not captured, cash not confirmed)
 * available  settled; will be included in the next payout
 * paid       included in a paid payout (payout_id)
 */
class DriverEarning extends Model
{
    public const PENDING = 'pending';

    public const AVAILABLE = 'available';

    public const PAID = 'paid';

    public const KIND_TRIP = 'trip';

    public const KIND_CANCELLATION_FEE = 'cancellation_fee';

    public const KIND_INCENTIVE = 'incentive';

    public const KIND_ADJUSTMENT = 'adjustment';

    protected $guarded = ['id'];

    protected $casts = [
        'fare_minor' => 'integer',
        'commission_minor' => 'integer',
        'deductions_minor' => 'integer',
        'incentive_minor' => 'integer',
        'net_minor' => 'integer',
        'cash_collected_minor' => 'integer',
        'estimate_minor' => 'integer',
        'breakdown' => 'array',
        'earned_at' => 'datetime',
        'available_at' => 'datetime',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function scopeForDriver(Builder $query, int $driverId): Builder
    {
        return $query->where('driver_id', $driverId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toStatementArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'ride_id' => $this->ride_id,
            'earned_at' => $this->earned_at?->toIso8601String(),
            'currency' => $this->currency,
            'fare_minor' => $this->fare_minor,
            'commission_minor' => $this->commission_minor,
            'deductions_minor' => $this->deductions_minor,
            'incentive_minor' => $this->incentive_minor,
            'net_minor' => $this->net_minor,
            'cash_collected_minor' => $this->cash_collected_minor,
            'estimate_minor' => $this->estimate_minor,
            'status' => $this->status,
            'payout_id' => $this->payout_id,
            'breakdown' => $this->breakdown,
        ];
    }
}
