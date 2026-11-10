<?php

namespace App\Domain\Integration\Dispatch;

use App\Domain\Dispatch\Contracts\DriverEligibility;
use App\Domain\Dispatch\Data\EligibilityResult;
use App\Domain\Verification\DriverEligibility as VerificationEligibility;
use App\Models\User;
use App\Models\Vehicle;

/**
 * Dispatch's go-online gate is the safety domain's verification gate (profile approval, own active vehicle,
 * every mandatory document approved and in date). Same answer as the DriverProfile guard, so the API refuses
 * with the driver's reasons (403) before the guard would ever have to throw.
 */
class VerificationDriverEligibilityAdapter implements DriverEligibility
{
    public function __construct(private readonly VerificationEligibility $verification) {}

    public function canGoOnline(User $driver, Vehicle $vehicle): EligibilityResult
    {
        $result = $this->verification->canGoOnline($driver, $vehicle);

        if ($result->allowed()) {
            return EligibilityResult::allow();
        }

        $reasons = [];

        foreach ($result->reasons as $reason) {
            $reasons[] = (string) $reason['code'];
            $messages[] = (string) $reason['message'];
        }

        return new EligibilityResult(false, $reasons, $messages ?? []);
    }
}
