<?php

namespace App\Http\Controllers\Driver\Support;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Payments\Models\Payment;
use App\Domain\Pricing\Models\RideFare;
use App\Models\Ride;
use App\Models\Vehicle;

/**
 * One trip as its driver sees it afterwards: the fare agreed at the request, the final fare with the pricing
 * domain's reason codes, and what the driver earned (the money domain's earning row once settled, otherwise the
 * pricing domain's split snapshot). Every amount is integer minor units read from its owner; nothing is computed
 * here except differences of two shown values.
 */
final class TripSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Ride $ride): array
    {
        $fare = RideFare::query()->where('ride_id', $ride->id)->first();
        $earning = DriverEarning::query()->where('ride_id', $ride->id)->where('kind', DriverEarning::KIND_TRIP)->where('driver_id', $ride->driver_id)->latest('id')->first();
        $cash = Payment::query()->where('ride_id', $ride->id)->where('purpose', Payment::PURPOSE_RIDE_FARE)->where('provider', 'cash')->latest('id')->first();
        $vehicle = $ride->vehicle_id ? Vehicle::query()->find($ride->vehicle_id, ['id', 'make', 'model', 'color', 'plate_number']) : null;

        $quoted = $fare?->estimated_total_minor ?? $ride->quoted_total_minor;
        $final = $fare?->final_total_minor ?? $ride->final_fare_minor;
        $currency = $fare?->currency ?? $ride->currency ?? 'ZAR';
        $snapshot = (array) ($fare?->earnings ?? []);

        $split = match (true) {
            $earning !== null => [
                'source' => 'statement',
                'status' => $earning->status,
                'fare_minor' => (int) $earning->fare_minor,
                'commission_minor' => $earning->commission_minor === null ? null : (int) $earning->commission_minor,
                // Platform fees, tax and other deductions: the difference of the shown figures, so the lines add up.
                'deductions_minor' => $earning->net_minor === null ? 0 : (int) $earning->fare_minor - (int) $earning->commission_minor - (int) $earning->net_minor,
                'net_minor' => $earning->net_minor === null ? null : (int) $earning->net_minor,
                'cash_collected_minor' => $earning->cash_collected_minor === null ? null : (int) $earning->cash_collected_minor,
                'earning_id' => $earning->id,
            ],
            $snapshot !== [] => [
                'source' => 'pricing',
                'status' => null,
                'fare_minor' => (int) ($snapshot['fare_minor'] ?? 0),
                'commission_minor' => isset($snapshot['commission_minor']) ? (int) $snapshot['commission_minor'] : null,
                'deductions_minor' => isset($snapshot['driver_net_minor']) ? (int) ($snapshot['fare_minor'] ?? 0) - (int) ($snapshot['commission_minor'] ?? 0) - (int) $snapshot['driver_net_minor'] : 0,
                'net_minor' => isset($snapshot['driver_net_minor']) ? (int) $snapshot['driver_net_minor'] : null,
                'cash_collected_minor' => null,
                'earning_id' => null,
            ],
            default => null,
        };

        $status = $ride->statusEnum();

        return [
            'ride_id' => $ride->id,
            'status' => $status->value,
            'status_label' => $status->label(),
            'pickup' => (string) $ride->pickup_address,
            'dropoff' => (string) $ride->dropoff_address,
            'started_at' => $ride->started_at?->toIso8601String(),
            'completed_at' => $ride->completed_at?->toIso8601String(),
            'category' => $ride->category_code ?? $ride->vehicle_type,
            'vehicle' => $vehicle ? trim("{$vehicle->color} {$vehicle->make} {$vehicle->model}").' '.$vehicle->plate_number : null,
            'currency' => $currency,
            'payment_method' => $ride->payment_method_kind ?? ($cash ? 'cash' : null),
            'fare' => [
                'quoted_minor' => $quoted,
                'final_minor' => $final,
                'difference_minor' => $quoted !== null && $final !== null ? $final - $quoted : null,
                'components' => array_values((array) ($fare?->final_components ?? $fare?->estimated_components ?? [])),
                'adjustments' => array_values((array) ($fare?->adjustments ?? [])),
                'finalised' => $fare?->isFinalised() ?? ($final !== null),
                'actual_distance_m' => $fare?->actual_distance_m,
                'actual_duration_s' => $fare?->actual_duration_s,
                'waiting_seconds' => $fare?->waiting_seconds,
            ],
            'earnings' => $split,
            'cash' => $cash === null ? null : [
                'status' => $cash->status,
                'expected_minor' => $cash->amountMinor(),
                'received_minor' => isset($cash->summary['cash_received_minor']) ? (int) $cash->summary['cash_received_minor'] : null,
                'confirmed' => $cash->status === Payment::PAID,
            ],
        ];
    }
}
