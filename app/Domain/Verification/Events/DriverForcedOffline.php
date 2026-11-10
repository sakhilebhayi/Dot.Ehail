<?php

namespace App\Domain\Verification\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A driver who was online stopped being eligible (a mandatory document expired or was rejected): the
 * verification domain took the profile offline. Dispatch's availability follows (integration D-714).
 */
final class DriverForcedOffline
{
    use Dispatchable;

    /**
     * @param  list<string>  $codes  eligibility reason codes
     */
    public function __construct(public readonly int $driverUserId, public readonly array $codes) {}
}
