<?php

namespace App\Domain\Verification;

use App\Models\User;
use App\Models\Vehicle;

/**
 * The go-online gate (docs/R46_ARCHITECTURE.md section 3). Dispatch's
 * AvailabilityService::goOnline must call canGoOnline() and refuse when allowed() is false.
 * Bound to VerificationDriverEligibility in EhailSafetyServiceProvider.
 */
interface DriverEligibility
{
    public function canGoOnline(User $driver, Vehicle $vehicle): EligibilityResult;
}
