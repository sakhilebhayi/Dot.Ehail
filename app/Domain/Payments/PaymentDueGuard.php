<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Models\Payment;
use App\Enums\PaymentStatus;
use App\Models\Ride;
use App\Models\User;

/**
 * Whether a passenger owes money that blocks a new ride request: a ride fare that failed after the
 * retries ran out or is still unpaid after the grace period, or a failed cancellation fee. Dispatch
 * calls blockingReason() before accepting a request (middleware alias "ehail.payment-clear").
 */
class PaymentDueGuard
{
    /**
     * @return array{blocked: bool, reason: ?string, rides: array<int, array{ride_id: int, amount_minor: int, currency: string, kind: string}>}
     */
    public function statusFor(User $passenger): array
    {
        $grace = now()->subMinutes(max(0, (int) config('money.charging.payment_due_grace_minutes', 15)));

        $rides = Ride::query()
            ->where('passenger_id', $passenger->id)
            ->where('status', 'completed')
            ->where(function ($q) use ($grace) {
                $q->where(function ($q) {
                    $q->where('payment_status', PaymentStatus::Failed->value)
                        ->whereNotExists(fn ($s) => $s->from('ride_payments')->whereColumn('ride_payments.ride_id', 'rides.id')
                            ->where('purpose', Payment::PURPOSE_RIDE_FARE)->where('status', Payment::FAILED)->whereNotNull('next_retry_at'));
                })->orWhere(fn ($q) => $q->where('payment_status', PaymentStatus::Unpaid->value)->where('completed_at', '<=', $grace))
                    // "Pending" only counts as settling while the server has a charge in flight. An opened checkout
                    // the passenger walked away from also says pending; it must not clear the block (R-46 security S-11).
                    ->orWhere(fn ($q) => $q->where('payment_status', PaymentStatus::Pending->value)->where('completed_at', '<=', $grace)
                        ->whereNotExists(fn ($s) => $s->from('ride_payments')->whereColumn('ride_payments.ride_id', 'rides.id')
                            ->where('purpose', Payment::PURPOSE_RIDE_FARE)->whereIn('status', [Payment::PENDING, Payment::AWAITING_CASH])));
            })
            ->get(['id', 'final_fare'])
            ->map(fn (Ride $r) => ['ride_id' => $r->id, 'amount_minor' => Money::decimalToMinor($r->final_fare ?? 0), 'currency' => Money::currency(), 'kind' => 'fare']);

        $fees = Payment::query()
            ->where('user_id', $passenger->id)
            ->where('purpose', Payment::PURPOSE_CANCELLATION_FEE)
            ->where('status', Payment::FAILED)
            ->whereNull('next_retry_at')
            ->whereNotExists(fn ($s) => $s->from('ride_payments as p2')->whereColumn('p2.ride_id', 'ride_payments.ride_id')
                ->where('p2.purpose', Payment::PURPOSE_CANCELLATION_FEE)->where('p2.status', Payment::PAID))
            ->get()
            ->unique('ride_id')
            ->map(fn (Payment $p) => ['ride_id' => (int) $p->ride_id, 'amount_minor' => $p->amountMinor(), 'currency' => $p->currency, 'kind' => 'cancellation_fee']);

        $owed = $rides->concat($fees)->values()->all();

        return [
            'blocked' => $owed !== [],
            'reason' => $owed === [] ? null : 'You have an unpaid amount from an earlier ride. Please settle it before requesting a new ride.',
            'rides' => $owed,
        ];
    }

    public function blockingReason(User $passenger): ?string
    {
        return $this->statusFor($passenger)['reason'];
    }
}
