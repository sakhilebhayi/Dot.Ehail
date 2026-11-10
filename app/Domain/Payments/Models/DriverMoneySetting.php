<?php

namespace App\Domain\Payments\Models;

use Illuminate\Database\Eloquent\Model;

class DriverMoneySetting extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['accepts_cash' => 'boolean'];

    public static function acceptsCash(int $driverUserId): bool
    {
        return (bool) static::where('user_id', $driverUserId)->value('accepts_cash');
    }
}
