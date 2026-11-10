<?php

namespace App\Domain\Payments\Seams;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Online time and offer acceptance come from the dispatch domain (driver_availability, ride_offers).
 * Null means "not measured": an incentive that needs it is shown as not measurable, never as met.
 */
interface DriverActivitySource
{
    public function onlineSeconds(User $driver, CarbonInterface $from, CarbonInterface $to): ?int;

    /** 0..1, or null when not measured. */
    public function acceptanceRate(User $driver, CarbonInterface $from, CarbonInterface $to): ?float;

    /** Qualified referrals the driver made in the window, or null when no referral system exists. */
    public function qualifiedReferrals(User $driver, CarbonInterface $from, CarbonInterface $to): ?int;
}
