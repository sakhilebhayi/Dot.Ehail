<?php

namespace App\Domain\Integration\Money;

use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Payments\Seams\DriverActivitySource;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Driver activity for incentives from dispatch's records (D-709):
 *  - acceptance rate: offers the driver answered or let lapse in the window (accepted / (accepted + declined +
 *    expired)); offers withdrawn by the system do not count; null with no such offers;
 *  - online seconds: null. driver_availability keeps only the current state, not a history of online
 *    sessions, so online time in a past window cannot be measured honestly (incentives needing it stay
 *    "not measurable");
 *  - referrals: null, no referral system exists.
 */
class DispatchDriverActivity implements DriverActivitySource
{
    public function onlineSeconds(User $driver, CarbonInterface $from, CarbonInterface $to): ?int
    {
        return null;
    }

    public function acceptanceRate(User $driver, CarbonInterface $from, CarbonInterface $to): ?float
    {
        $counts = RideOffer::query()
            ->where('driver_id', $driver->id)
            ->whereBetween('sent_at', [$from, $to])
            ->whereIn('status', [RideOffer::ACCEPTED, RideOffer::DECLINED, RideOffer::EXPIRED])
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $total = (int) $counts->sum();

        return $total === 0 ? null : round(((int) ($counts[RideOffer::ACCEPTED] ?? 0)) / $total, 4);
    }

    public function qualifiedReferrals(User $driver, CarbonInterface $from, CarbonInterface $to): ?int
    {
        return null;
    }
}
