<?php

namespace App\Domain\Payments;

use App\Domain\Ledger\FinanceIssues;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Ride;
use App\Notifications\RidePaymentNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server-side charges of a ride fare or cancellation fee against a saved method.
 *
 * Exactly-once rules:
 *  - One payment row per (ride, purpose, attempt), idempotency key "ride:{id}:{purpose}:{n}", created
 *    under the ride row lock. A second request while an attempt is pending or paid gets that attempt
 *    back and never sends a second charge.
 *  - The gateway call happens after the row is committed, with our reference; Paystack refuses a
 *    reused reference, and an unknown outcome stays "pending" until verify answers.
 *  - A row becomes paid only from a verified gateway answer (GatewayResult::SUCCEEDED), applied under
 *    the payment row lock; an already final row is never changed again.
 */
class ChargeAttempts
{
    public function __construct(
        private PaymentGateways $gateways,
        private Settlement $settlement,
        private FinanceIssues $issues,
    ) {}

    /**
     * Charge (or return the live attempt for) a ride's fare or fee.
     */
    public function charge(Ride $ride, string $purpose, int $amountMinor, string $currency, string $gateway, ?PaymentMethod $method): Payment
    {
        $payment = $this->openAttempt($ride, $purpose, $amountMinor, $currency, $gateway, $method);

        if ($payment->wasRecentlyCreated !== true) {
            return $payment;
        }

        $payer = $ride->passenger()->first();
        $result = $this->gateways->driver($gateway)->capture($payment, $method, (string) $payer?->email);

        return $this->apply($payment->id, $result);
    }

    /**
     * Apply a gateway answer to a payment row exactly once.
     */
    public function apply(int $paymentId, GatewayResult $result): Payment
    {
        $succeeded = false;

        $payment = DB::transaction(function () use ($paymentId, $result, &$succeeded) {
            $rideId = Payment::whereKey($paymentId)->value('ride_id');
            $ride = $rideId === null ? null : Ride::whereKey($rideId)->lockForUpdate()->first();
            $payment = Payment::whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if (in_array($payment->status, [Payment::PAID, Payment::FAILED, Payment::UNMATCHED, Payment::EXPIRED], true)) {
                return $payment;
            }

            $summary = array_merge((array) $payment->summary, $result->summary);

            switch ($result->status) {
                case GatewayResult::SUCCEEDED:
                    $payment->forceFill([
                        'status' => Payment::PAID,
                        'provider_transaction_id' => $result->providerId ?? $payment->provider_transaction_id,
                        'channel' => $result->channel ?? $payment->channel,
                        'paid_at' => now(),
                        'verified_at' => now(),
                        'failure_code' => null,
                        'failure_message' => null,
                        'next_retry_at' => null,
                        'summary' => $summary,
                    ])->save();
                    $this->setRideStatus($ride, $payment, PaymentStatus::Paid);
                    $succeeded = true;
                    break;

                case GatewayResult::FAILED:
                    $retryAt = $this->nextRetryAt($payment);
                    $payment->forceFill([
                        'status' => Payment::FAILED,
                        'failure_code' => mb_substr((string) $result->code, 0, 40),
                        'failure_message' => mb_substr((string) $result->message, 0, 255),
                        'next_retry_at' => $retryAt,
                        'summary' => $summary,
                    ])->save();
                    $this->setRideStatus($ride, $payment, PaymentStatus::Failed);

                    if ($ride !== null && $payment->purpose !== Payment::PURPOSE_CARD_SETUP) {
                        DB::afterCommit(fn () => $this->notifyFailure($ride, $payment, $retryAt === null));
                    }
                    break;

                case GatewayResult::AWAITING_CASH:
                    $payment->forceFill(['status' => Payment::AWAITING_CASH])->save();
                    $this->setRideStatus($ride, $payment, PaymentStatus::AwaitingCash);
                    break;

                default: // pending or unavailable: outcome unknown, verified later
                    $payment->forceFill(['status' => Payment::PENDING, 'summary' => $summary])->save();
                    $this->setRideStatus($ride, $payment, PaymentStatus::Pending);
            }

            return $payment;
        });

        if ($succeeded) {
            $this->settlement->paymentSucceeded($payment->fresh());
        }

        return $payment->fresh();
    }

    /**
     * Ask the gateway again about a pending charge (sweeper, webhook).
     */
    public function reconcile(Payment $payment): Payment
    {
        if ($payment->status !== Payment::PENDING) {
            return $payment;
        }

        $result = $this->gateways->driver($payment->provider)->verify($payment);

        // A charge Paystack has never heard of, long after we sent it, was never made.
        if ($result->status === GatewayResult::FAILED && $result->code === 'not_found'
            && $payment->created_at->lt(now()->subMinutes(10)) === false) {
            return $payment;
        }

        return $this->apply($payment->id, $result);
    }

    /**
     * Charge failed rides whose retry time has come, with the passenger's current method.
     *
     * @return int retries started
     */
    public function retryDue(callable $methodFor): int
    {
        $count = 0;

        Payment::query()
            ->where('status', Payment::FAILED)
            ->whereIn('purpose', [Payment::PURPOSE_RIDE_FARE, Payment::PURPOSE_CANCELLATION_FEE])
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (Payment $failed) use ($methodFor, &$count) {
                // Claim the retry: only the request that clears next_retry_at goes on.
                $claimed = Payment::whereKey($failed->id)->whereNotNull('next_retry_at')->update(['next_retry_at' => null]);
                $ride = $failed->ride;

                if ($claimed === 0 || $ride === null) {
                    return;
                }

                [$gateway, $method] = $methodFor($ride, $failed);

                if ($gateway === null) {
                    return;
                }

                $this->charge($ride, $failed->purpose, $failed->amountMinor(), $failed->currency, $gateway, $method);
                $count++;
            });

        return $count;
    }

    /**
     * Create the next attempt row, or return the live one (pending, awaiting cash, paid).
     */
    private function openAttempt(Ride $ride, string $purpose, int $amountMinor, string $currency, string $gateway, ?PaymentMethod $method): Payment
    {
        for ($try = 1; ; $try++) {
            try {
                return DB::transaction(function () use ($ride, $purpose, $amountMinor, $currency, $gateway, $method) {
                    $locked = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();

                    $live = Payment::where('ride_id', $locked->id)->where('purpose', $purpose)
                        ->whereIn('status', [Payment::PAID, Payment::PENDING, Payment::AWAITING_CASH])
                        ->latest('id')->first();

                    if ($live !== null) {
                        return $live;
                    }

                    $attempt = (int) Payment::where('ride_id', $locked->id)->where('purpose', $purpose)->max('attempt') + 1;
                    $code = ['ride_fare' => 'f', 'cancellation_fee' => 'c'][$purpose] ?? 'x';

                    return Payment::create([
                        'ride_id' => $locked->id,
                        'user_id' => $locked->passenger_id,
                        'payment_method_id' => $method?->id,
                        'provider' => $gateway === 'card' ? 'paystack' : $gateway,
                        'purpose' => $purpose,
                        'attempt' => $attempt,
                        'idempotency_key' => "ride:{$locked->id}:{$purpose}:{$attempt}",
                        'reference' => "ehail-{$locked->id}-{$code}{$attempt}-".Str::lower(Str::random(10)),
                        'amount_cents' => $amountMinor,
                        'currency' => $currency,
                        'status' => Payment::PENDING,
                    ]);
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= 3) {
                    throw $e;
                }
            }
        }
    }

    private function nextRetryAt(Payment $payment): ?Carbon
    {
        $delays = array_values((array) config('money.charging.retry_minutes', []));
        $index = $payment->attempt - 1;

        return $payment->purpose !== Payment::PURPOSE_CARD_SETUP && isset($delays[$index]) && $delays[$index] > 0
            ? now()->addMinutes($delays[$index])
            : null;
    }

    private function setRideStatus(?Ride $ride, Payment $payment, PaymentStatus $status): void
    {
        if ($ride === null || $payment->purpose !== Payment::PURPOSE_RIDE_FARE) {
            return;
        }

        if ($ride->paymentStatusEnum() === PaymentStatus::Paid || $ride->paymentStatusEnum() === PaymentStatus::Waived) {
            return;
        }

        $ride->forceFill(['payment_status' => $status->value])->save();
    }

    private function notifyFailure(Ride $ride, Payment $payment, bool $exhausted): void
    {
        $amount = Money::format($payment->amountMinor(), $payment->currency);
        $what = $payment->purpose === Payment::PURPOSE_CANCELLATION_FEE ? 'cancellation fee' : 'fare';

        $message = $exhausted
            ? "We could not charge the {$amount} {$what} for ride #{$ride->id}. Please update your payment method and pay it; new ride requests are paused until it is settled."
            : "Your card was declined for the {$amount} {$what} of ride #{$ride->id}. We will try again automatically, or you can pay now with another method.";

        $ride->passenger?->notify(new RidePaymentNotification($ride, 'ride_payment_failed', 'Payment declined', $message));

        if ($exhausted) {
            $this->issues->raise('payment_retries_exhausted', "retries-exhausted:{$payment->id}", "Ride #{$ride->id}: {$what} declined after every retry", [
                'subject_type' => 'payment', 'subject_id' => $payment->id, 'ride_id' => $ride->id,
                'amount_minor' => $payment->amountMinor(), 'currency' => $payment->currency,
                'details' => ['failure_code' => $payment->failure_code, 'attempt' => $payment->attempt],
            ]);
        }
    }
}
