<?php

namespace App\Domain\Dispatch\Models;

use App\Domain\Rides\FeedVersion;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One offer of one ride to one driver. Sequential dispatch keeps at most one
 * pending offer per ride and per driver (pending_ride_id / pending_driver_id
 * are unique and only filled while the offer is pending). Status changes are
 * compare-and-swap updates in Dispatcher, never a read-modify-write.
 */
class RideOffer extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'ride_id', 'driver_id', 'vehicle_id', 'status', 'pending_ride_id', 'pending_driver_id', 'round',
        'radius_m', 'pickup_distance_m', 'pickup_eta_s', 'eta_method', 'score', 'score_terms',
        'sent_at', 'seen_at', 'expires_at', 'responded_at', 'reason',
    ];

    protected $casts = [
        'score_terms' => 'array',
        'score' => 'float',
        'sent_at' => 'datetime',
        'seen_at' => 'datetime',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
        'round' => 'integer',
        'radius_m' => 'integer',
        'pickup_distance_m' => 'integer',
        'pickup_eta_s' => 'integer',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(fn (RideOffer $offer) => $offer->version = FeedVersion::next((int) $offer->version));
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function isLive(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }
}
