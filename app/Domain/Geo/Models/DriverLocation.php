<?php

namespace App\Domain\Geo\Models;

use App\Domain\Geo\LatLng;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The latest accepted position of one driver. Sensitive (POPIA): read only through LocationStore and the
 * policy-checked endpoints; never serialised to anyone but the assigned passenger, the driver and operators.
 */
class DriverLocation extends Model
{
    protected $fillable = ['driver_id', 'lat', 'lng', 'accuracy_m', 'speed_mps', 'heading_deg', 'recorded_at', 'received_at'];

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

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
