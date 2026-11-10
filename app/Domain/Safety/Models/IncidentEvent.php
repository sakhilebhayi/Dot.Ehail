<?php

namespace App\Domain\Safety\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only timeline entry of a safety incident. */
class IncidentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Incident timelines are append-only.'));
        static::deleting(fn () => throw new LogicException('Incident timelines are append-only.'));
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(SafetyIncident::class, 'safety_incident_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
