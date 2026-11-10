<?php

namespace App\Domain\Geo\Models;

use App\Domain\Geo\LatLng;
use App\Models\Ride;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sampled point of a trip in progress. Raw points are deleted after ehail-geo.locations.trip_points_retention_days.
 */
class TripPoint extends Model
{
    public $timestamps = false;

    protected $fillable = ['ride_id', 'driver_id', 'lat', 'lng', 'accuracy_m', 'speed_mps', 'heading_deg', 'recorded_at', 'received_at'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'accuracy_m' => 'float',
            'speed_mps' => 'float',
            'heading_deg' => 'float',
            'recorded_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    public function position(): LatLng
    {
        return new LatLng($this->lat, $this->lng);
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }
}
