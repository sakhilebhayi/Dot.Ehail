<?php

namespace App\Models;

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
