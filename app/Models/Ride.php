<?php

namespace App\Models;

use App\Enums\RideActor;
use App\Enums\RideStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ride extends Model
{
    protected $fillable = [
        'passenger_id', 'driver_id', 'vehicle_id',
        'pickup_address', 'pickup_lat', 'pickup_lng',
        'dropoff_address', 'dropoff_lat', 'dropoff_lng',
        'status', 'vehicle_type', 'estimated_fare', 'final_fare',
        'distance_km', 'requested_at', 'accepted_at', 'completed_at',
        'started_at', 'cancelled_at', 'expired_at', 'needs_review_at',
        'cancelled_by', 'cancel_reason', 'cancel_note', 'reassignment_count',
        'final_fare_reason', 'final_fare_set_by',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'accepted_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expired_at' => 'datetime',
        'needs_review_at' => 'datetime',
        'estimated_fare' => 'decimal:2',
        'final_fare' => 'decimal:2',
        'reassignment_count' => 'integer',
    ];

    protected static function booted(): void
    {
        // The waiting window (ehail.ride_expiry_minutes) is measured from
        // requested_at, so a ride never exists without one.
        static::creating(function (Ride $ride): void {
            $ride->requested_at ??= now();
        });
    }

    public function statusEnum(): RideStatus
    {
        return RideStatus::from($this->status);
    }

    /**
     * The part $user plays on this ride, or null if they are not a party to
     * it. A platform operator who is neither passenger nor driver acts as the
     * operator.
     */
    public function actorFor(User $user): ?RideActor
    {
        return match (true) {
            $user->id === $this->passenger_id => RideActor::Passenger,
            $this->driver_id !== null && $user->id === $this->driver_id => RideActor::Driver,
            $user->isPlatformOperator() => RideActor::Operator,
            default => null,
        };
    }

    /**
     * Rides a user is entitled to see: their own (as passenger or as the
     * assigned driver), the rides of drivers in fleets they own (approved
     * fleets only), and everything for a platform operator.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isPlatformOperator()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('passenger_id', $user->id)
                ->orWhere('driver_id', $user->id)
                ->orWhereIn('driver_id', static::fleetDriverUserIds($user));
        });
    }

    /**
     * Rides driven by drivers belonging to approved fleets the user owns.
     */
    public function scopeDrivenInFleetsOwnedBy(Builder $query, User $user): Builder
    {
        return $query->whereIn('driver_id', static::fleetDriverUserIds($user));
    }

    /**
     * user_ids of every driver profile in an approved fleet owned by $user.
     * DriverProfile carries HasUserScope, which would silently filter this
     * to the authenticated user's own row, so it is bypassed explicitly.
     */
    public static function fleetDriverUserIds(User $user): Builder
    {
        return DriverProfile::withoutGlobalScope('user')
            ->select('user_id')
            ->whereIn('fleet_id', Fleet::approved()->where('owner_user_id', $user->id)->select('id'));
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function rating(): HasOne
    {
        return $this->hasOne(RideRating::class);
    }
}
