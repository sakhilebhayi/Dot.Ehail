<?php

namespace App\Domain\Fleet\Models;

use App\Models\DriverProfile;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who holds a fleet vehicle, from when to when. Open while active_vehicle_id is set (unique: one active driver per
 * vehicle, enforced by the database).
 */
class FleetVehicleAssignment extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class)->withoutGlobalScope('user');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotNull('active_vehicle_id');
    }
}
