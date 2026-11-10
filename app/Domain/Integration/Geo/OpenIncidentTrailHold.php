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
            // Open incidents hold the raw trail; a resolved or closed one keeps holding it for the incident-trail period,
            // so evidence does not vanish the day a case closes (R-46 security S-28, docs/R46_PRIVACY.md section 5).
            ->where(fn ($q) => $q->whereIn('status', [IncidentStatus::Open->value, IncidentStatus::Acknowledged->value, IncidentStatus::Escalated->value])
                ->orWhere('updated_at', '>=', now()->subDays(max(0, (int) config('ehail_security.retention.incident_trail', 365)))))
            ->distinct()
            ->pluck('ride_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
