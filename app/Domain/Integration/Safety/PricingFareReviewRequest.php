<?php

namespace App\Domain\Integration\Safety;

use App\Domain\Pricing\Models\RideFare;
use App\Domain\Support\Models\SupportCase;
use App\Domain\Support\Seams\FareAdjustmentRequest;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;

/**
 * A fare dispute opened through support goes to the operators' review with the ride's full fare record from
 * pricing (quoted and final totals, every adjustment and its reason). A dispute never changes a fare by
 * itself: an operator decides and, if needed, uses pricing's audited adjustment
 * (POST /ops/pricing/rides/{ride}/fare-adjustments); any refund is a separate, operator-made money refund.
 */
class PricingFareReviewRequest implements FareAdjustmentRequest
{
    public function request(Ride $ride, SupportCase $case, User $requester, string $reason, ?int $requestedAmountMinor = null): array
    {
        $fare = RideFare::query()->where('ride_id', $ride->id)->first();

        $case->forceFill(['meta' => array_merge((array) $case->meta, ['fare_review' => [
            'status' => 'submitted',
            'ride_id' => $ride->id,
            'reason' => mb_substr($reason, 0, 500),
            'requested_amount_minor' => $requestedAmountMinor,
            'requested_at' => now()->toIso8601String(),
            'fare' => $fare === null ? null : [
                'currency' => $fare->currency,
                'quoted_total_minor' => $fare->estimated_total_minor,
                'final_total_minor' => $fare->final_total_minor,
                'basis' => $fare->basis,
                'adjustments' => $fare->adjustments ?? [],
            ],
        ]])])->save();

        AuditLog::record('pricing.fare_review_requested', $requester, $ride, ['support_case_id' => $case->id, 'requested_amount_minor' => $requestedAmountMinor]);

        return [
            'status' => 'submitted',
            'reference' => (string) $case->reference,
            'message' => $fare === null
                ? 'Your dispute is with an operator. This ride has no detailed fare record, so they will check it by hand.'
                : 'Your dispute is with an operator, who can see how the fare was worked out and correct it if it is wrong.',
        ];
    }
}
