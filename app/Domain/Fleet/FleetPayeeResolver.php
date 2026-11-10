<?php

namespace App\Domain\Fleet;

use App\Domain\Payments\Seams\PayeeResolver;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;

/**
 * Money's PayeeResolver with the per-fleet arrangement (docs/R46_MONEY.md section 11, "wave-2 fleet arrangements"):
 * fleets.payee_mode = 'fleet_owner' (the fleet owner is paid its drivers' earnings), 'driver' (each driver is paid
 * directly), or null (the platform setting money.payouts.payee_for_fleet_drivers). Only an approved fleet's owner
 * can be the payee, and an owner who drives in their own fleet is always paid as the driver (same person).
 */
class FleetPayeeResolver implements PayeeResolver
{
    public function payeeFor(User $driver): array
    {
        $fleetId = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->value('fleet_id');
        $fleet = $fleetId === null ? null : Fleet::query()->find($fleetId);

        if ($fleet !== null && $fleet->isApproved() && (int) $fleet->owner_user_id !== $driver->id) {
            $mode = $fleet->payee_mode ?? config('money.payouts.payee_for_fleet_drivers');

            if ($mode === 'fleet_owner') {
                return ['payee_type' => 'fleet_owner', 'payee_user_id' => (int) $fleet->owner_user_id, 'fleet_id' => (int) $fleet->id];
            }
        }

        return ['payee_type' => 'driver', 'payee_user_id' => $driver->id, 'fleet_id' => $fleetId === null ? null : (int) $fleetId];
    }
}
