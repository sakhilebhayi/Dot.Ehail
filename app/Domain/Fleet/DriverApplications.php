<?php

namespace App\Domain\Fleet;

use App\Domain\Fleet\Exceptions\FleetActionRefused;
use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Services\Notify\EhailNotifyEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deciding a driver's application to join a fleet (the legacy flow, moved here so the legacy URLs and the fleet
 * portal share one rule): FleetPolicy::reviewDriver decides who may (never the applicant; operators; the owner of
 * an approved fleet), plus managers of an approved fleet with the drivers area. Only a pending application can be
 * decided, under a row lock so two reviewers cannot both decide it.
 */
final class DriverApplications
{
    public function __construct(private readonly FleetAccess $access) {}

    public function canDecide(User $reviewer, Fleet $fleet, DriverProfile $profile): bool
    {
        if (Gate::forUser($reviewer)->allows('reviewDriver', [$fleet, $profile])) {
            return true;
        }

        return $fleet->isApproved()
            && $reviewer->id !== $profile->user_id
            && $this->access->membership($reviewer, $fleet)?->hasScope('drivers') === true;
    }

    public function approve(Fleet $fleet, int $profileId, User $reviewer): DriverProfile
    {
        return $this->decide($fleet, $profileId, $reviewer, 'approved');
    }

    public function reject(Fleet $fleet, int $profileId, User $reviewer, string $reason): DriverProfile
    {
        return $this->decide($fleet, $profileId, $reviewer, 'rejected', $reason);
    }

    private function decide(Fleet $fleet, int $profileId, User $reviewer, string $outcome, ?string $reason = null): DriverProfile
    {
        $profile = DB::transaction(function () use ($fleet, $profileId, $reviewer, $outcome, $reason) {
            $profile = DriverProfile::withoutGlobalScope('user')->where('fleet_id', $fleet->id)->lockForUpdate()->findOrFail($profileId);

            if (! $this->canDecide($reviewer, $fleet, $profile)) {
                throw new FleetActionRefused('not_allowed', 'You cannot decide this application.', 403);
            }

            if ($profile->status !== 'pending') {
                throw new FleetActionRefused('already_decided', 'Only a pending application can be '.$outcome.'.');
            }

            $profile->update([
                'status' => $outcome,
                'rejected_reason' => $outcome === 'rejected' ? $reason : null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            AuditLog::record('fleet.driver_application_'.$outcome, $reviewer, $profile, ['fleet_id' => $fleet->id]);

            return $profile;
        });

        app(EhailNotifyEvents::class)->driverApplicationDecided($profile, $outcome);

        return $profile;
    }
}
