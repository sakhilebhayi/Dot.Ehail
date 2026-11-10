<?php

namespace App\Domain\Fleet\Models;

use App\Models\DriverProfile;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This driver may drive this fleet vehicle." Only permitted drivers can be assigned the vehicle.
 */
class FleetVehiclePermit extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['revoked_at' => 'datetime'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class)->withoutGlobalScope('user');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
