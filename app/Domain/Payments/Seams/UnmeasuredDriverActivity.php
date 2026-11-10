<?php

namespace App\Domain\Payments\Seams;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Bound until dispatch (availability, offers) and a referral system exist: nothing is measured.
 */
class UnmeasuredDriverActivity implements DriverActivitySource
{
    public function onlineSeconds(User $driver, CarbonInterface $from, CarbonInterface $to): ?int
    {
        return null;
    }

    public function acceptanceRate(User $driver, CarbonInterface $from, CarbonInterface $to): ?float
    {
        return null;
    }

    public function qualifiedReferrals(User $driver, CarbonInterface $from, CarbonInterface $to): ?int
    {
        return null;
    }
}
