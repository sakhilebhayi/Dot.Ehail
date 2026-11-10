<?php

namespace App\Domain\Payments\Seams;

use App\Models\Ride;
use App\Models\User;

/**
 * Hand a money problem (cash discrepancy, disputed charge) to the safety/support domain's case queue.
 * Returns the case reference, or null when no support domain is bound (the finance issue still exists).
 */
interface SupportCaseOpener
{
    public function open(string $kind, ?Ride $ride, User $raisedBy, string $summary): ?string;
}
