<?php

namespace App\Domain\Fleet;

use App\Domain\Fleet\Models\FleetVehicleAssignment;
use App\Domain\Verification\DriverEligibility;
use App\Domain\Verification\EligibilityResult;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;

/**
 * The fleet's part of the go-online gate, layered on the verification domain's DriverEligibility (it decorates the
 * binding, so dispatch's AvailabilityService, the integration adapter and the DriverProfile is_online guard all ask
 * the same question). Adds, never removes, reasons:
 *   vehicle_out_of_service    the fleet took the vehicle off the road (maintenance flag)
 *   vehicle_retired           the fleet retired the vehicle
 *   fleet_vehicle_unassigned  a fleet-held vehicle without an open assignment to this driver
 */
final class FleetAwareDriverEligibility implements DriverEligibility
{
    public function __construct(private readonly DriverEligibility $inner) {}

    public function canGoOnline(User $driver, Vehicle $vehicle): EligibilityResult
    {
        $result = $this->inner->canGoOnline($driver, $vehicle);
        $reasons = $result->reasons;

        if ($vehicle->out_of_service_at !== null) {
            $why = $vehicle->out_of_service_reason ? ' ('.$vehicle->out_of_service_reason.')' : '';
            $reasons[] = ['code' => 'vehicle_out_of_service', 'subject' => 'vehicle', 'type' => null, 'message' => 'Your fleet has taken this vehicle out of service'.$why.'.'];
        }

        if ($vehicle->retired_at !== null) {
            $reasons[] = ['code' => 'vehicle_retired', 'subject' => 'vehicle', 'type' => null, 'message' => 'Your fleet has retired this vehicle.'];
        }

        if ($vehicle->owned_by === 'fleet') {
            $profileId = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->value('id');
            $assigned = $profileId !== null && FleetVehicleAssignment::query()->open()
                ->where('vehicle_id', $vehicle->id)->where('driver_profile_id', $profileId)->exists();

            if (! $assigned) {
                $reasons[] = ['code' => 'fleet_vehicle_unassigned', 'subject' => 'vehicle', 'type' => null, 'message' => 'Your fleet has not given you this vehicle.'];
            }
        }

        return count($reasons) === count($result->reasons) ? $result : new EligibilityResult($reasons, $result->documentsEnforced);
    }
}
