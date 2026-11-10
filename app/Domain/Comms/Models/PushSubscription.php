<?php

namespace App\Domain\Comms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A browser's Web Push subscription. The endpoint and the keys are encrypted at rest; the endpoint
 * hash keeps it unique. Revoked when the person turns push off on that device or the push service
 * answers 404/410 (the subscription is gone).
 */
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth_secret', 'device_label', 'revoked_at', 'revoke_reason'];

    protected $hidden = ['endpoint', 'p256dh', 'auth_secret', 'endpoint_hash'];

    protected $casts = [
        'endpoint' => 'encrypted',
        'p256dh' => 'encrypted',
        'auth_secret' => 'encrypted',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'revoked_at' => 'datetime',
        'failure_count' => 'integer',
    ];

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
