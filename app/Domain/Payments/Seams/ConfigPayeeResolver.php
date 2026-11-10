<?php

namespace App\Domain\Payments\Seams;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;

/**
 * money.payouts.payee_for_fleet_drivers = 'driver' (default: every driver is paid directly) or
 * 'fleet_owner' (earnings of drivers in an approved fleet are owed to the fleet owner). The wave-2
 * fleet domain can replace this with a per-fleet arrangement.
 */
class ConfigPayeeResolver implements PayeeResolver
{
    public function payeeFor(User $driver): array
    {
        $fleetId = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->value('fleet_id');

        if ($fleetId !== null && config('money.payouts.payee_for_fleet_drivers') === 'fleet_owner') {
            $owner = Fleet::query()->whereKey($fleetId)->value('owner_user_id');

            if ($owner !== null && (int) $owner !== $driver->id) {
                return ['payee_type' => 'fleet_owner', 'payee_user_id' => (int) $owner, 'fleet_id' => (int) $fleetId];
            }
        }

        return ['payee_type' => 'driver', 'payee_user_id' => $driver->id, 'fleet_id' => $fleetId === null ? null : (int) $fleetId];
    }
}
