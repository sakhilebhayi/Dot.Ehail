<?php

namespace App\Domain\Payments\Seams;

use App\Models\User;

/**
 * Who is paid a driver's earnings: the driver, or the owner of the driver's fleet.
 */
interface PayeeResolver
{
    /**
     * @return array{payee_type: 'driver'|'fleet_owner', payee_user_id: int, fleet_id: ?int}
     */
    public function payeeFor(User $driver): array;
}
