<?php

namespace App\Domain\Dispatch\Models;

use App\Domain\Rides\FeedVersion;
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
        'location_version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (DriverAvailability $row): void {
            if ($row->exists && ! $row->isDirty()) {
                return;
            }

            // The driver's own feed changes only with state, vehicle or ride. A heartbeat or a new position (every
            // few seconds) moves location_version, which the passenger feed follows, and leaves version alone, so an
            // idle driver's poll answers 304 instead of resending the same feed (docs/R46_PERF.md, D-1504).
            $presenceOnly = ['last_heartbeat_at', 'last_lat', 'last_lng', 'heading', 'last_location_at', 'updated_at'];

            if (! $row->exists || array_diff(array_keys($row->getDirty()), $presenceOnly) !== []) {
                $row->version = FeedVersion::next((int) $row->version);
            }

            if ($row->isDirty('last_location_at') || $row->isDirty('last_lat') || $row->isDirty('last_lng')) {
                $row->location_version = FeedVersion::next((int) $row->location_version);
            }
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
