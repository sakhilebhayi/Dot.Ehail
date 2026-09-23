<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDocument extends Model
{
    protected $fillable = ['driver_profile_id', 'type', 'file_path', 'original_filename'];

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class);
    }
}
