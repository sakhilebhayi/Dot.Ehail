<?php

namespace App\Domain\Comms\Models;

use Illuminate\Database\Eloquent\Model;

/** One person's choice for one category and one extra channel (push, email or sms). */
class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'category', 'channel', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];
}
