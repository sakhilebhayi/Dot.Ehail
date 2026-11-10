<?php

namespace App\Domain\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A client-supplied Idempotency-Key and the response it produced, kept for
 * 24 hours (config ehail_dispatch.idempotency_hours). A replay with the same
 * key and the same body returns the stored response; the same key with a
 * different body is refused (422).
 */
class IdempotencyKey extends Model
{
    protected $fillable = ['user_id', 'scope', 'key', 'request_hash', 'response_status', 'response_body', 'ride_id', 'expires_at'];

    protected $casts = [
        'response_body' => 'array',
        'expires_at' => 'datetime',
        'response_status' => 'integer',
    ];
}
