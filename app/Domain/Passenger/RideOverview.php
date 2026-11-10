<?php

namespace App\Domain\Passenger;

use App\Domain\Integration\Dispatch\PricingCancellationFees;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\Receipt;
use App\Domain\Payments\Models\RidePaymentChoice;
use App\Domain\Pricing\Finalisation\CancellationFeePolicy;
use App\Domain\Pricing\Models\RideFare;
use App\Enums\PaymentStatus;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\RideRating;
use App\Models\User;

/**
 * The parts of a ride the passenger app shows that the dispatch feed does not carry (D-804): the fare rules the
 * ride was quoted under (free waiting and cancellation minutes, fees), what cancelling right now would cost, the
 * payment state from money, the receipt, and whether the passenger has rated. Read-only; every number comes from
 * the owning domain's rows.
 *
 * The cancellation preview asks pricing's CancellationFeePolicy::decide() directly, never the dispatch
 * CancellationFees seam: that seam settles the ride's fare as a cancellation (it runs inside the cancel
 * transition), so calling it for a preview would cancel the fare of a live ride (D-805).
 */
class RideOverview
{
    public function __construct(private readonly CancellationFeePolicy $policy) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Ride $ride, User $passenger): array
    {
        $status = $ride->statusEnum();
        $fare = RideFare::query()->where('ride_id', $ride->id)->with('ruleSet')->first();
        $rules = $fare?->ruleSet;
        $receipt = Receipt::query()->where('ride_id', $ride->id)->where('passenger_id', $passenger->id)->orderBy('id')->first();
        $paymentStatus = $ride->paymentStatusEnum();
        $choice = RidePaymentChoice::query()->where('ride_id', $ride->id)->first();
        $method = $choice?->payment_method_id ? PaymentMethod::query()->where('user_id', $passenger->id)->find($choice->payment_method_id) : null;

        return [
            'ride_id' => $ride->id,
            'status' => $status->value,
            'currency' => $ride->currency ?? $rules?->currency ?? 'ZAR',
            'policy' => $rules === null ? null : [
                'cancel_free_minutes' => (int) $rules->cancel_free_minutes,
                'cancel_fee_minor' => (int) $rules->cancel_fee_minor,
                'wait_free_minutes' => (int) $rules->wait_free_minutes,
                'wait_per_minute_minor' => (int) $rules->wait_per_minute_minor,
                'no_show_fee_minor' => (int) $rules->no_show_fee_minor,
                'tariff_is_example' => (bool) $rules->is_example,
            ],
            'cancellation_now' => $this->cancellationNow($ride, $status),
            'payment' => [
                'status' => $paymentStatus->value,
                'label' => $paymentStatus->label(),
                'method' => $choice?->method ?? $ride->payment_method_kind,
                'method_label' => $this->methodLabel($choice?->method ?? $ride->payment_method_kind, $method),
                'can_pay_now' => $status === RideStatus::Completed && in_array($paymentStatus, [PaymentStatus::Failed, PaymentStatus::Unpaid], true),
            ],
            'receipt' => $receipt === null ? null : [
                'id' => $receipt->id,
                'number' => $receipt->number,
                'url' => route('money.receipts.show', $receipt),
            ],
            'rated' => RideRating::query()->where('ride_id', $ride->id)->where('rated_by', $passenger->id)->exists(),
            'can_rate' => $status === RideStatus::Completed && $ride->driver_id !== null,
        ];
    }

    /**
     * @return array{fee_minor: int, reason: string}|null
     */
    private function cancellationNow(Ride $ride, RideStatus $status): ?array
    {
        if (! $status->isAwaitingDriver() && ! in_array($status, [RideStatus::DriverAssigned, RideStatus::DriverArriving, RideStatus::DriverArrived], true)) {
            return null;
        }

        try {
            $late = class_exists(PricingCancellationFees::class) ? app(PricingCancellationFees::class)->driverRunningLate($ride, now()) : false;
            $decision = $this->policy->decide($ride, RideActor::Passenger, now(), null, null, $late);

            return ['fee_minor' => $decision->charges() ? $decision->fee->amountMinor : 0, 'reason' => $decision->text];
        } catch (\Throwable) {
            return null;
        }
    }

    private function methodLabel(?string $kind, ?PaymentMethod $method): ?string
    {
        return match ($kind) {
            RidePaymentChoice::CARD => $method?->maskedLabel() ?? 'Card',
            RidePaymentChoice::CASH => 'Cash to the driver',
            RidePaymentChoice::BUSINESS_ACCOUNT => $method?->maskedLabel() ?? 'Business account',
            'checkout' => 'Card, paid after the trip',
            null => null,
            default => ucfirst(str_replace('_', ' ', $kind)),
        };
    }
}
