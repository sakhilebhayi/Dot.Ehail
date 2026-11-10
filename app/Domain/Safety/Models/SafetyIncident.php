<?php

namespace App\Domain\Safety\Models;

use App\Domain\Safety\IncidentKind;
use App\Domain\Safety\IncidentSeverity;
use App\Domain\Safety\IncidentStatus;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A safety incident (docs/R46_ARCHITECTURE.md section 7). Changed only through
 * App\Domain\Safety\IncidentService; every change appends an IncidentEvent.
 *
 * @property IncidentKind $kind
 * @property IncidentSeverity $severity
 * @property IncidentStatus $status
 */
class SafetyIncident extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => IncidentKind::class,
            'severity' => IncidentSeverity::class,
            'status' => IncidentStatus::class,
            'context' => 'array',
            'escalation_level' => 'integer',
            'next_escalation_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'location_captured_at' => 'datetime',
            'location_lat' => 'float',
            'location_lng' => 'float',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class)->orderBy('id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(IncidentNotification::class)->orderBy('id');
    }
}
