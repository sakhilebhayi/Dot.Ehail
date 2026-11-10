<?php

namespace App\Domain\Support\Models;

use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Support\SupportCategory;
use App\Domain\Support\SupportPriority;
use App\Domain\Support\SupportStatus;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A support case. Changed through App\Domain\Support\SupportCaseService only.
 *
 * @property SupportCategory $category
 * @property SupportStatus $status
 * @property SupportPriority $priority
 */
class SupportCase extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category' => SupportCategory::class,
            'status' => SupportStatus::class,
            'priority' => SupportPriority::class,
            'meta' => 'array',
            'escalation_level' => 'integer',
            'first_response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'escalated_at' => 'datetime',
            'sla_breached_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(SafetyIncident::class, 'safety_incident_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }
}
