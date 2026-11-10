<?php

namespace App\Domain\Safety;

use App\Domain\Safety\Models\IncidentEvent;
use App\Domain\Safety\Models\SafetyIncident;
use App\Models\User;

class IncidentTimeline
{
    /** @param array<string, mixed> $data */
    public function add(SafetyIncident $incident, ?User $actor, string $type, array $data = []): IncidentEvent
    {
        return IncidentEvent::create([
            'safety_incident_id' => $incident->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'data' => $data,
        ]);
    }
}
