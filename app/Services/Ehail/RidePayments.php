<?php

namespace App\Services\Ehail;

use App\Enums\PaymentStatus;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\RidePayment;
use App\Models\User;
use App\Notifications\RidePaymentNotification;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackConfig;
use App\Services\Payments\Paystack\PaystackTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The passenger pays the platform's Paystack account the ride's final fare after the ride completes.
 * Everything that changes a ride's payment state goes through here.
 *
 * Rules that hold everywhere:
 *  - The amount is the ride's stored final fare in integer cents. Nothing from the client is used.
 *  - A ride only becomes paid when a Paystack transaction is VERIFIED (by the verify endpoint on the
 *    passenger's return, or by a signed charge.success webhook) with status success AND the same
 *    reference, currency and amount as our attempt AND the ride still owing exactly that fare.
 *  - A charge that does not match is never dropped: the attempt becomes 'unmatched' with a reason and
 *    appears on the operator desk, because the payer may have been charged.
 *  - Callback and webhook can arrive together, twice, or in either order. Each takes the ride row lock
 *    first and the payment row lock second, re-reads, and does nothing if the payment is already
 *    settled; unique(reference) and unique(provider, provider_transaction_id) are the backstop.
 *  - Money lands in the platform's Paystack balance. Nothing here pays a driver or a fleet.
 */
class RidePayments
{
    public const PAID = 'paid';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public const UNMATCHED = 'unmatched';

    public const NONE = 'none';

    private const IGNORED = 'ignored';

    public function __construct(
        private readonly PaystackConfig $config,
        private readonly PaystackClient $client,
    ) {}

    public function enabled(): bool
    {
        return $this->config->canTakePayments();
    }

    /**
     * The payment status a ride gets the moment it completes: unpaid only when Paystack is usable and
     * the fare is above zero, otherwise nothing is due (exactly how rides completed before payments).
     */
    public function statusOnCompletion(mixed $finalFare): PaymentStatus
    {
        if (! $this->enabled() || $finalFare === null) {
            return PaymentStatus::NotDue;
        }

        return self::toCents($finalFare) > 0 ? PaymentStatus::Unpaid : PaymentStatus::NotDue;
    }

    /**
     * Stored decimal(8,2) fare to integer cents with no float arithmetic on the way out: format to two
     * places (half up) and drop the point.
     */
    public static function toCents(mixed $amount): int
    {
        return (int) str_replace('.', '', number_format((float) $amount, 2, '.', ''));
    }

    public function fareCents(Ride $ride): int
    {
        return self::toCents($ride->final_fare ?? 0);
    }

    public function currency(): string
    {
        return strtoupper((string) config('ehail.currency.code', 'ZAR'));
    }

    /**
     * Start (or reopen) the payment of a fare. One live attempt per ride: a second click within the
     * attempt's lifetime returns the same checkout. Runs with the ride row locked; if Paystack cannot be
     * reached the transaction rolls back and nothing about the ride or its payments has changed.
     *
     * @throws PaymentNotPossible
     * @throws PaymentGatewayException
     */
    public function start(Ride $ride, User $payer): RidePayment
    {
        return DB::transaction(function () use ($ride, $payer) {
            $locked = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();
            $cents = $this->assertPayable($locked, $payer);

            RidePayment::where('ride_id', $locked->id)
                ->where('status', RidePayment::STATUS_INITIATED)
                ->where('expires_at', '<=', now())
                ->update(['status' => RidePayment::STATUS_EXPIRED]);

            $live = RidePayment::where('ride_id', $locked->id)
                ->where('status', RidePayment::STATUS_INITIATED)
                ->where('expires_at', '>', now())
                ->where('amount_cents', $cents)
                ->whereNotNull('authorization_url')
                ->latest('id')
                ->first();

            if ($live !== null) {
                return $live;
            }

            $payment = RidePayment::create([
                'ride_id' => $locked->id,
                'user_id' => $payer->id,
                'provider' => 'paystack',
                'reference' => 'ehail-'.$locked->id.'-'.Str::lower(Str::random(14)),
                'amount_cents' => $cents,
                'currency' => $this->currency(),
                'status' => RidePayment::STATUS_INITIATED,
                'expires_at' => now()->addMinutes($this->config->attemptTtlMinutes()),
            ]);

            $started = $this->client->initializeTransaction(
                $payer->email,
                $cents,
                $payment->currency,
                $payment->reference,
                route('rides.pay.callback', $locked),
                ['ride_id' => $locked->id, 'purpose' => 'ride_fare'],
            );

            $payment->update(['authorization_url' => $started->authorizationUrl]);
            $locked->forceFill(['payment_status' => PaymentStatus::Pending->value])->save();

            return $payment;
        });
    }

    /**
     * The passenger came back from Paystack. Ask Paystack (never the query string) what happened to the
     * ride's latest unsettled attempt and settle accordingly. A Paystack outage throws and changes nothing;
     * the webhook, or a second visit, settles it later.
     *
     * @return self::PAID|self::PENDING|self::FAILED|self::UNMATCHED|self::NONE
     *
     * @throws PaymentGatewayException
     */
    public function confirmReturn(Ride $ride): string
    {
        $payment = RidePayment::where('ride_id', $ride->id)
            ->whereIn('status', [RidePayment::STATUS_INITIATED, RidePayment::STATUS_EXPIRED])
            ->latest('id')
            ->first();

        if ($payment === null) {
            return $ride->fresh()->paymentStatusEnum() === PaymentStatus::Paid ? self::PAID : self::NONE;
        }

        return $this->settle($payment->id, $this->client->verifyTransaction($payment->reference), 'verify');
    }

    /**
     * A signed Paystack webhook, already authenticated and parsed. Only charge.success matters; any
     * other event is acknowledged and ignored. The signed payload's amounts are in subunits (cents).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): string
    {
        if (($payload['event'] ?? null) !== 'charge.success' || ! is_array($payload['data'] ?? null)) {
            return self::IGNORED;
        }

        try {
            $transaction = PaystackTransaction::fromData($payload['data']);
        } catch (PaymentGatewayException) {
            return self::IGNORED;
        }

        $payment = RidePayment::where('reference', $transaction->reference)->first();

        return $payment === null
            ? $this->recordUnknown($transaction)
            : $this->settle($payment->id, $transaction, 'webhook');
    }

    /**
     * An operator writes a fare off. The reason is mandatory and the waiver is audited. Any open
     * checkout is retired; if the passenger pays on it anyway, that charge is flagged on the desk
     * (the money did arrive) rather than silently ignored.
     *
     * @throws PaymentNotPossible
     */
    public function waive(Ride $ride, User $operator, string $reason): Ride
    {
        return DB::transaction(function () use ($ride, $operator, $reason) {
            $locked = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->paymentStatusEnum();

            if ($locked->status !== 'completed' || ! in_array($previous, PaymentStatus::collectable(), true)) {
                throw new PaymentNotPossible('Only a completed ride with an unpaid fare can be waived.');
            }

            $locked->forceFill([
                'payment_status' => PaymentStatus::Waived->value,
                'fare_waived_reason' => $reason,
                'fare_waived_by' => $operator->id,
                'fare_waived_at' => now(),
            ])->save();

            RidePayment::where('ride_id', $locked->id)
                ->where('status', RidePayment::STATUS_INITIATED)
                ->update(['status' => RidePayment::STATUS_EXPIRED]);

            AuditLog::record('ride.fare_waived', $operator, $locked, [
                'previous_payment_status' => $previous->value,
                'amount_cents' => $this->fareCents($locked),
                'currency' => $this->currency(),
                'reason' => $reason,
            ]);

            $this->notify($locked->passenger, $locked, 'ride_fare_waived', 'Fare waived',
                "The fare for ride #{$locked->id} was waived by a platform operator. Nothing is due.");

            return $locked;
        });
    }

    /**
     * An operator has looked at an unmatched charge and recorded what they did about it.
     *
     * @throws PaymentNotPossible
     */
    public function markReviewed(RidePayment $payment, User $operator, string $note): RidePayment
    {
        return DB::transaction(function () use ($payment, $operator, $note) {
            $locked = RidePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RidePayment::STATUS_UNMATCHED || $locked->reviewed_at !== null) {
                throw new PaymentNotPossible('That payment is not waiting for review.');
            }

            $locked->update(['reviewed_at' => now(), 'reviewed_by' => $operator->id, 'review_note' => $note]);

            AuditLog::record('ride.payment_reviewed', $operator, $locked, [
                'reason' => $locked->unmatched_reason,
                'amount_cents' => $locked->amount_cents,
                'note' => $note,
            ]);

            return $locked;
        });
    }

    /**
     * Apply a Paystack transaction to one of our attempts, exactly once. See the class docblock.
     *
     * @param  'verify'|'webhook'  $source
     * @return self::PAID|self::PENDING|self::FAILED|self::UNMATCHED
     */
    private function settle(int $paymentId, PaystackTransaction $transaction, string $source): string
    {
        for ($try = 1; ; $try++) {
            try {
                return DB::transaction(fn () => $this->settleLocked($paymentId, $transaction, $source));
            } catch (UniqueConstraintViolationException $e) {
                // Another request recorded this transaction between our check and our write. The
                // transaction is rolled back; one more pass sees the winner's row and settles to it.
                if ($try >= 2) {
                    throw $e;
                }
            }
        }
    }

    private function settleLocked(int $paymentId, PaystackTransaction $transaction, string $source): string
    {
        // Lock order is always ride, then payment, so a callback and a webhook cannot deadlock.
        $rideId = RidePayment::whereKey($paymentId)->value('ride_id');
        $ride = $rideId === null ? null : Ride::whereKey($rideId)->lockForUpdate()->first();
        $payment = RidePayment::whereKey($paymentId)->lockForUpdate()->firstOrFail();

        if ($payment->status === RidePayment::STATUS_PAID) {
            return self::PAID;
        }

        if ($payment->status === RidePayment::STATUS_UNMATCHED) {
            return self::UNMATCHED;
        }

        if (! $transaction->isSuccessful()) {
            return $source === 'verify' && $transaction->status === 'failed'
                ? $this->markFailed($payment, $ride, $transaction)
                : self::PENDING;
        }

        $mismatch = match (true) {
            $transaction->reference !== $payment->reference => 'reference_mismatch',
            $transaction->currency !== strtoupper($payment->currency) => 'currency_mismatch',
            $transaction->amountCents !== $payment->amount_cents => 'amount_mismatch',
            default => null,
        };

        $reason = $mismatch ?? match (true) {
            $ride === null || $ride->status !== 'completed' => 'ride_not_payable',
            $ride->paymentStatusEnum() === PaymentStatus::Paid => 'duplicate_payment',
            ! in_array($ride->paymentStatusEnum(), PaymentStatus::collectable(), true) => 'ride_not_payable',
            $this->fareCents($ride) !== $payment->amount_cents => 'fare_changed',
            RidePayment::where('provider', $payment->provider)->where('provider_transaction_id', $transaction->id)->whereKeyNot($payment->id)->exists() => 'duplicate_transaction',
            default => null,
        };

        if ($reason !== null) {
            $payment->update([
                'status' => RidePayment::STATUS_UNMATCHED,
                'unmatched_reason' => $reason,
                'summary' => $transaction->safePayload($source),
            ]);

            return self::UNMATCHED;
        }

        $payment->update([
            'status' => RidePayment::STATUS_PAID,
            'provider_transaction_id' => $transaction->id,
            'paid_at' => now(),
            'summary' => $transaction->safePayload($source),
        ]);
        $ride->forceFill(['payment_status' => PaymentStatus::Paid->value])->save();

        $this->notifyPaid($ride->load(['passenger', 'driver']), $payment);

        return self::PAID;
    }

    private function markFailed(RidePayment $payment, ?Ride $ride, PaystackTransaction $transaction): string
    {
        $payment->update(['status' => RidePayment::STATUS_FAILED, 'summary' => $transaction->safePayload('verify')]);

        if ($ride !== null && in_array($ride->paymentStatusEnum(), PaymentStatus::collectable(), true)) {
            $ride->forceFill(['payment_status' => PaymentStatus::Failed->value])->save();
        }

        return self::FAILED;
    }

    /**
     * Paystack reported a charge whose reference we never issued. Keep one record of it for the desk
     * (and none of the payer's details), answer 200 so Paystack stops retrying.
     */
    private function recordUnknown(PaystackTransaction $transaction): string
    {
        try {
            RidePayment::create([
                'ride_id' => null,
                'provider' => 'paystack',
                'reference' => mb_substr($transaction->reference, 0, 100),
                'amount_cents' => $transaction->amountCents,
                'currency' => mb_substr($transaction->currency, 0, 3),
                'status' => RidePayment::STATUS_UNMATCHED,
                'unmatched_reason' => 'unknown_reference',
                'summary' => $transaction->safePayload('webhook'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same webhook recorded it first.
        }

        return self::UNMATCHED;
    }

    /**
     * @throws PaymentNotPossible
     */
    private function assertPayable(Ride $ride, User $payer): int
    {
        if (! $this->enabled()) {
            throw new PaymentNotPossible('Online payment is not enabled right now, so this fare cannot be paid here.');
        }

        if ($ride->passenger_id !== $payer->id) {
            throw new PaymentNotPossible('Only the passenger can pay this fare.');
        }

        if ($ride->status !== 'completed') {
            throw new PaymentNotPossible('A fare can be paid once the ride is completed.');
        }

        $state = $ride->paymentStatusEnum();
        $cents = $this->fareCents($ride);

        return match (true) {
            $state === PaymentStatus::Paid => throw new PaymentNotPossible('This fare is already paid.'),
            $state === PaymentStatus::Waived => throw new PaymentNotPossible('This fare was waived. Nothing is due.'),
            $state === PaymentStatus::NotDue || $cents <= 0 => throw new PaymentNotPossible('No payment is due for this ride.'),
            default => $cents,
        };
    }

    private function notifyPaid(Ride $ride, RidePayment $payment): void
    {
        $amount = FareCalculator::formatCents($payment->amount_cents);

        $this->notify($ride->passenger, $ride, 'ride_paid', 'Fare paid',
            "Your payment of {$amount} for ride #{$ride->id} was received. Thank you.");

        $this->notify($ride->driver, $ride, 'ride_paid', 'Passenger paid the fare',
            "The passenger paid the {$amount} fare for ride #{$ride->id}. Payout to drivers is not available yet: the fare is recorded and settlement to drivers is manual.");
    }

    private function notify(?User $user, Ride $ride, string $type, string $title, string $message): void
    {
        $user?->notify(new RidePaymentNotification($ride, $type, $title, $message));
    }
}
