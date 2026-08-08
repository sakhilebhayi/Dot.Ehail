<?php

namespace App\Policies;

use App\Models\Fleet;
use App\Models\User;

class FleetPolicy
{
    /**
     * Only the fleet's owner may view it (its pending/reviewed driver
     * applications) or act on its drivers.
     */
    public function view(User $user, Fleet $fleet): bool
    {
        return $user->id === $fleet->owner_user_id;
    }
}
