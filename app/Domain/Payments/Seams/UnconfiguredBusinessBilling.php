<?php

namespace App\Domain\Payments\Seams;

use App\Models\User;
use LogicException;

/**
 * No business accounts domain yet: the business_account method cannot be chosen and nothing is charged.
 */
class UnconfiguredBusinessBilling implements BusinessBilling
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function canCharge(int $businessAccountId, User $traveller, int $amountMinor, string $currency): BusinessChargeDecision
    {
        return BusinessChargeDecision::deny('Business accounts are not available yet.');
    }

    public function record(int $businessAccountId, User $traveller, int $amountMinor, string $currency, int $rideId): string
    {
        throw new LogicException('Business accounts are not available yet.');
    }
}
