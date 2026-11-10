<?php

namespace App\Domain\Ops\Availability;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One span of a driver's dispatch state (online, on_trip, paused, offline). Written by AvailabilitySpanRecorder.
 *
 * @property int $driver_id
 * @property string $state
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 */
class AvailabilitySpan extends Model
{
    public $timestamps = false;

    protected $table = 'ops_availability_spans';

    protected $fillable = ['driver_id', 'state', 'started_at', 'ended_at'];

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime'];
}
