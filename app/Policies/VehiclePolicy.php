<?php

namespace App\Policies;

use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;

/**
 * A driver manages their own vehicles, and only while their driver profile is
 * pending or approved: a suspended or rejected driver keeps read access to the
 * list but cannot change anything.
 */
class VehiclePolicy
{
    private const MAY_MANAGE = ['pending', 'approved'];

    public function create(User $user): bool
    {
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $user->id)->first();

        return $profile !== null && in_array($profile->status, self::MAY_MANAGE, true);
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $this->owns($user, $vehicle);
    }

    public function activate(User $user, Vehicle $vehicle): bool
    {
        return $this->owns($user, $vehicle);
    }

    public function deactivate(User $user, Vehicle $vehicle): bool
    {
        return $this->owns($user, $vehicle);
    }

    private function owns(User $user, Vehicle $vehicle): bool
    {
        $profile = $vehicle->driverProfile()->withoutGlobalScope('user')->first();

        return $profile !== null
            && $profile->user_id === $user->id
            && in_array($profile->status, self::MAY_MANAGE, true);
    }
}
