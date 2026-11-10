<?php

namespace App\Domain\Comms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A mobile number a person confirmed for safety SMS (encrypted). Only collected when an SMS provider
 * is configured; used for nothing else.
 */
class SmsNumber extends Model
{
    protected $table = 'notification_sms_numbers';

    protected $fillable = ['user_id', 'number', 'verified_at', 'code_hash', 'code_expires_at', 'code_attempts', 'code_sent_at'];

    protected $hidden = ['number', 'code_hash'];

    protected $casts = [
        'number' => 'encrypted',
        'verified_at' => 'datetime',
        'code_expires_at' => 'datetime',
        'code_sent_at' => 'datetime',
        'code_attempts' => 'integer',
    ];

    public function maskedNumber(): string
    {
        $number = (string) $this->number;

        return strlen($number) <= 4 ? '****' : str_repeat('•', max(0, strlen($number) - 4)).substr($number, -4);
    }
}
