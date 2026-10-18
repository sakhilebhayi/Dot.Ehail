<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'accepted_at' => 'datetime',
        'completed_at' => 'datetime',
        'estimated_fare' => 'decimal:2',
        'final_fare' => 'decimal:2',
    ];

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
