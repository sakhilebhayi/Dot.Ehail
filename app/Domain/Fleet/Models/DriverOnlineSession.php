<?php

namespace App\Domain\Fleet\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stretch of time a driver spent out of the offline state (online, paused or on a trip), recorded from dispatch's
 * availability changes. Measured from the day this table was created; never back-filled or estimated.
 */
class DriverOnlineSession extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime'];
}
