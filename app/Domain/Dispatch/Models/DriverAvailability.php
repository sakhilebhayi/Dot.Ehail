<?php

namespace App\Domain\Dispatch\Models;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A driver's dispatch state: offline, online, on_trip or paused, the vehicle
 * in use, when the state began, the last heartbeat and the latest position
 * (one row per driver, overwritten; no history is kept here).
 *
 * Changed only through AvailabilityService and RideStateMachine.
 */
class DriverAvailability extends Model
{
    public const OFFLINE = 'offline';

    public const ONLINE = 'online';

    public const ON_TRIP = 'on_trip';

    public const PAUSED = 'paused';

    protected $table = 'driver_availability';

    protected $fillable = [
        'user_id', 'vehicle_id', 'state', 'since', 'online_since', 'last_heartbeat_at',
        'last_lat', 'last_lng', 'heading', 'last_location_at', 'current_ride_id', 'last_trip_ended_at',
    ];

    protected $casts = [
        'since' => 'datetime',
        'online_since' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'last_location_at' => 'datetime',
        'last_trip_ended_at' => 'datetime',
        'last_lat' => 'float',
        'last_lng' => 'float',
        'heading' => 'integer',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (DriverAvailability $row): void {
            if ($row->exists && ! $row->isDirty()) {
                return;
            }

            $row->version = max((int) now()->getPreciseTimestamp(3), (int) $row->version + 1);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * No heartbeat (or no position) within the window: not offered rides.
     */
    public function isStale(): bool
    {
        $window = (int) config('ehail_dispatch.heartbeat_stale_seconds');

        return $this->last_heartbeat_at === null
            || $this->last_heartbeat_at->lt(now()->subSeconds($window));
    }

    public function hasFreshLocation(): bool
    {
        $window = (int) config('ehail_dispatch.heartbeat_stale_seconds');

        return $this->last_location_at !== null
            && $this->last_lat !== null
            && $this->last_location_at->gte(now()->subSeconds($window));
    }
}
