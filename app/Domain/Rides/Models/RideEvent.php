<?php

namespace App\Domain\Rides\Models;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A ride's append-only timeline: every state transition, offer and operator
 * action, with who, what, when, a reason code and a context object. Written
 * only through RideTimeline::append(); never updated or deleted by the
 * application (the guard is at the model layer, like AuditLog).
 *
 * @property int $sequence
 */
class RideEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ride_id', 'sequence', 'type', 'from_status', 'to_status', 'actor_type', 'actor_id',
        'reason_code', 'context', 'transition_key', 'occurred_at',
    ];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
        'sequence' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ride events are append-only.'));
        static::deleting(fn () => throw new LogicException('Ride events are append-only.'));
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
