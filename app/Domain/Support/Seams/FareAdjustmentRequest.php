<?php

namespace App\Domain\Support\Seams;

use App\Domain\Support\Models\SupportCase;
use App\Models\Ride;
use App\Models\User;

/**
 * Pricing seam: a fare dispute asks the pricing domain to review a ride's fare. Pricing owns the
 * decision (FareFinaliser / operator adjustment with an audited reason, contract sections 3-4) and
 * binds its own implementation; until then RecordOnlyFareAdjustmentRequest records the request on
 * the case and says plainly that pricing is not connected.
 */
interface FareAdjustmentRequest
{
    /**
     * @return array{status: 'submitted'|'not_connected', reference: ?string, message: string}
     */
    public function request(Ride $ride, SupportCase $case, User $requester, string $reason, ?int $requestedAmountMinor = null): array;
}
