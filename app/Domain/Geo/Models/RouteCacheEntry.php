<?php

namespace App\Domain\Geo\Models;

use Illuminate\Database\Eloquent\Model;

class RouteCacheEntry extends Model
{
    protected $table = 'route_cache';

    protected $fillable = ['cache_key', 'provider', 'profile', 'distance_m', 'duration_s', 'polyline', 'expires_at'];

    protected function casts(): array
    {
        return [
            'distance_m' => 'integer',
            'duration_s' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
