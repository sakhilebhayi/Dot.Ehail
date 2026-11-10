<?php

namespace App\Domain\Geo\Models;

use App\Domain\Geo\Geometry\Polyline;
use App\Domain\Geo\LatLng;
use App\Models\Ride;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ride's planned road route (deviation is measured against it) and its measured distance summary.
 */
class RideRoute extends Model
{
    protected $fillable = [
        'ride_id', 'provider', 'planned_distance_m', 'planned_duration_s', 'planned_polyline', 'planned_at',
        'travelled_m', 'billable_m', 'distance_method', 'distance_quality', 'trail_purged_at',
    ];

    protected function casts(): array
    {
        return [
            'planned_distance_m' => 'integer',
            'planned_duration_s' => 'integer',
            'planned_at' => 'immutable_datetime',
            'travelled_m' => 'integer',
            'billable_m' => 'integer',
            'trail_purged_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return list<LatLng>
     */
    public function plannedPoints(): array
    {
        return filled($this->planned_polyline) ? Polyline::decode($this->planned_polyline) : [];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }
}
