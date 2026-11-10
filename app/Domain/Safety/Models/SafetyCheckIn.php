<?php

namespace App\Domain\Safety\Models;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Is everything OK?" prompt to one party after a route deviation or an unusual stop.
 * pending -> ok | need_help | unanswered.
 */
class SafetyCheckIn extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'prompted_at' => 'datetime',
            'respond_by' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(SafetyIncident::class, 'safety_incident_id');
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'ride_id' => $this->ride_id,
            'trigger' => $this->trigger,
            'status' => $this->status,
            'prompted_at' => $this->prompted_at?->toIso8601String(),
            'respond_by' => $this->respond_by?->toIso8601String(),
            'question' => $this->trigger === 'unusual_stop'
                ? 'The car has stopped for a while somewhere unexpected. Are you OK?'
                : 'The trip has left the expected route. Are you OK?',
        ];
    }
}
