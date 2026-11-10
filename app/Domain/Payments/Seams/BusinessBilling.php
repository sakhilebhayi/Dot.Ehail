<?php

namespace App\Domain\Payments\Seams;

use App\Models\User;

/**
 * Seam for the wave-2 business accounts domain. canCharge says whether a business account will pay
 * for this traveller's trip (limits, policy); record books the charge on the account's invoice.
 */
interface BusinessBilling
{
    public function isAvailable(): bool;

    public function canCharge(int $businessAccountId, User $traveller, int $amountMinor, string $currency): BusinessChargeDecision;

    /** Returns the business domain's charge reference. */
    public function record(int $businessAccountId, User $traveller, int $amountMinor, string $currency, int $rideId): string;
}
