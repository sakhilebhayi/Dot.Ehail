<?php

namespace App\Domain\Payments\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A signed provider webhook as received (customer details removed), keyed for idempotency.
 */
class WebhookEvent extends Model
{
    protected $table = 'payment_webhook_events';

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
    ];
}
