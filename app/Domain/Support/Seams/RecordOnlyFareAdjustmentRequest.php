<?php

namespace App\Domain\Support\Seams;

use App\Domain\Support\Models\SupportCase;
use App\Models\Ride;
use App\Models\User;

/**
 * Stub until the pricing domain binds FareAdjustmentRequest: nothing is adjusted, the request is
 * kept on the case (meta.fare_review) for an operator, and the answer says "not_connected".
 */
class RecordOnlyFareAdjustmentRequest implements FareAdjustmentRequest
{
    public function request(Ride $ride, SupportCase $case, User $requester, string $reason, ?int $requestedAmountMinor = null): array
    {
        $case->forceFill(['meta' => array_merge((array) $case->meta, ['fare_review' => [
            'status' => 'not_connected',
            'ride_id' => $ride->id,
            'reason' => mb_substr($reason, 0, 500),
            'requested_amount_minor' => $requestedAmountMinor,
            'requested_at' => now()->toIso8601String(),
        ]])])->save();

        return [
            'status' => 'not_connected',
            'reference' => null,
            'message' => 'Your dispute is recorded for an operator. Automatic fare review is not connected yet.',
        ];
    }
}
