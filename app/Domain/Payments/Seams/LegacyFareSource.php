<?php

namespace App\Domain\Payments\Seams;

use App\Domain\Payments\Money;
use App\Models\Ride;

/**
 * Reads the fare the existing lifecycle stores on the ride (rides.final_fare, decimal) until the
 * pricing domain's FareFinaliser is bound. No promotions, tax or fees are invented: the whole fare is
 * one "Trip fare" line. An operator-entered fare carries its reason.
 */
class LegacyFareSource implements FareSource
{
    public function finalFareFor(Ride $ride): ?FareBreakdown
    {
        if ($ride->final_fare === null) {
            return null;
        }

        $gross = Money::decimalToMinor($ride->final_fare);
        $estimate = $ride->estimated_fare === null ? null : Money::decimalToMinor($ride->estimated_fare);
        $reasons = [];

        if ($ride->final_fare_set_by !== null) {
            $reasons[] = ['code' => 'operator_adjusted', 'label' => 'Fare set by an operator: '.(string) $ride->final_fare_reason];
        } elseif ($estimate !== null && $estimate !== $gross) {
            $reasons[] = ['code' => 'fare_changed', 'label' => 'Final fare differs from the estimate'];
        }

        $duration = $ride->started_at !== null && $ride->completed_at !== null
            ? max(0, (int) $ride->started_at->diffInSeconds($ride->completed_at))
            : null;

        return new FareBreakdown(
            grossMinor: $gross,
            currency: Money::currency(),
            components: [['code' => 'fare', 'label' => 'Trip fare', 'amount_minor' => $gross]],
            estimateMinor: $estimate,
            reasons: $reasons,
            distanceM: $ride->distance_km === null ? null : (int) round(((float) $ride->distance_km) * 1000),
            durationS: $duration,
            source: 'rides.final_fare',
        );
    }
}
