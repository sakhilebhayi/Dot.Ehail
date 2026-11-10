<?php

namespace App\Domain\Dispatch\Contracts;

use App\Domain\Dispatch\Data\EligibilityResult;
use App\Models\User;
use App\Models\Vehicle;

/**
 * Seam to the safety domain's VerificationService (docs/R46_ARCHITECTURE.md
 * section 3): may this driver go online in this vehicle right now? The
 * default binding (Seams\ProfileApprovalEligibility) applies the approval
 * rules that exist today; safety rebinds it to document verification.
 */
interface DriverEligibility
{
    public function canGoOnline(User $driver, Vehicle $vehicle): EligibilityResult;
}
