<?php

namespace App\Domain\Payments\Policies;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Earnings\Models\Payout;
use App\Domain\Payments\Models\Receipt;
use App\Models\Fleet;
use App\Models\User;

/**
 * Who may see which money records (registered in EhailMoneyServiceProvider):
 *  - a receipt: its passenger, or a platform operator;
 *  - an earning: its driver, the owner of the (approved) fleet it was earned in, or an operator;
 *  - a payout: its payee, or an operator;
 *  - a fleet's earnings: the fleet's owner (approved fleet), or an operator.
 */
class MoneyPolicies
{
    public function viewReceipt(User $user, Receipt $receipt): bool
    {
        return $receipt->passenger_id === $user->id || $user->isPlatformOperator();
    }

    public function viewEarning(User $user, DriverEarning $earning): bool
    {
        return $earning->driver_id === $user->id
            || $user->isPlatformOperator()
            || ($earning->fleet_id !== null && Fleet::approved()->whereKey($earning->fleet_id)->where('owner_user_id', $user->id)->exists());
    }

    public function viewPayout(User $user, Payout $payout): bool
    {
        return $payout->payee_user_id === $user->id || $user->isPlatformOperator();
    }

    public function viewFleetEarnings(User $user, Fleet $fleet): bool
    {
        return $user->isPlatformOperator() || ($fleet->owner_user_id === $user->id && Fleet::approved()->whereKey($fleet->id)->exists());
    }
}
