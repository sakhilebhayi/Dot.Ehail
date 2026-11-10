<?php

namespace App\Domain\Integration\Geo;

use App\Domain\Geo\Locations\TrailRetentionHold;
use App\Domain\Safety\IncidentStatus;
use App\Domain\Safety\Models\SafetyIncident;

/**
 * Geo keeps the raw trail of a ride that has a safety incident not yet resolved or closed (evidence), past
 * the normal 90-day purge (D-707).
 */
class OpenIncidentTrailHold implements TrailRetentionHold
{
    public function held(array $rideIds): array
    {
        if ($rideIds === []) {
            return [];
        }

        return SafetyIncident::query()
            ->whereIn('ride_id', $rideIds)
            ->whereIn('status', [IncidentStatus::Open->value, IncidentStatus::Acknowledged->value, IncidentStatus::Escalated->value])
            ->distinct()
            ->pluck('ride_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
