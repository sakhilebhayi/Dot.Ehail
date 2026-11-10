<?php

namespace App\Domain\Payments\Seams;

use App\Domain\Payments\Money;
use App\Models\Ride;
use App\Models\User;

/**
 * One commission rate for everyone (config money.commission.percent), applied to the fare net of
 * tax and platform fees. Promotions are platform-funded and never reduce the driver's share.
 */
class ConfigCommissionSplitter implements EarningsSplitSource
{
    public function split(Ride $ride, FareBreakdown $fare, User $driver): EarningsSplit
    {
        $percent = (float) config('money.commission.percent', 20);
        $base = max(0, $fare->grossMinor - $fare->taxMinor - $fare->platformFeesMinor);
        $commission = Money::percentOf($base, $percent);
        $driverGross = $base - $commission;

        return new EarningsSplit(
            commissionMinor: $commission,
            driverGrossMinor: $driverGross,
            deductions: [],
            driverNetMinor: $driverGross,
            plan: rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'% commission (platform setting)',
        );
    }
}
