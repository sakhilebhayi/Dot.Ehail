<?php

namespace App\Domain\Payments;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\FinanceIssues;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\PostingRules;
use App\Domain\Payments\Exceptions\PaymentMethodRefused;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\RidePaymentNotification;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackRefund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Refunds (operator only). Full or partial, never more than what is left of the payment. Card
 * payments go back through Paystack's refund API and are recorded as processed only when Paystack
 * says so (refund webhook, then GET /refund/{id}); cash and business payments are refunded by the
 * operator outside the app and recorded as manual, processed immediately.
 */
class Refunds
{
    public function __construct(
        private PaymentGateways $gateways,
        private Ledger $ledger,
        private PostingRules $rules,
        private FinanceIssues $issues,
        private PaystackClient $client,
    ) {}

    /**
     * @throws PaymentMethodRefused
     */
    public function request(Payment $payment, User $operator, int $amountMinor, string $reason, ?string $note, bool $chargeBackDriver): Refund
    {
        if (! $operator->isPlatformOperator()) {
            throw new PaymentMethodRefused('Only a platform operator can refund.');
        }

        if (! array_key_exists($reason, Refund::REASONS) || $reason === 'card_setup_verification') {
            throw new PaymentMethodRefused('Choose a refund reason.');
        }

        $refund = DB::transaction(function () use ($payment, $operator, $amountMinor, $reason, $note, $chargeBackDriver) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Payment::PAID || $locked->purpose === Payment::PURPOSE_CARD_SETUP) {
                throw new PaymentMethodRefused('Only a paid fare or fee can be refunded.');
            }

            $open = (int) Refund::where('payment_id', $locked->id)->whereIn('status', [Refund::REQUESTED, Refund::PENDING, Refund::PROCESSING])->sum('amount_minor');
            $left = $locked->refundableMinor() - $open;

            if ($amountMinor < 1 || $amountMinor > $left) {
                throw new PaymentMethodRefused('The refund must be between '.Money::format(1, $locked->currency).' and '.Money::format(max(0, $left), $locked->currency).'.');
            }

            $refund = Refund::create([
                'payment_id' => $locked->id,
                'ride_id' => $locked->ride_id,
                'amount_minor' => $amountMinor,
                'currency' => $locked->currency,
                'reason' => $reason,
                'note' => $note === null ? null : mb_substr($note, 0, 500),
                'charge_back_driver' => $chargeBackDriver && $locked->ride_id !== null,
                'method' => $locked->provider === 'paystack' ? 'paystack' : 'manual',
                'status' => Refund::REQUESTED,
                'idempotency_key' => 'refund:'.$locked->id.':'.Str::uuid(),
                'requested_by' => $operator->id,
            ]);

            AuditLog::record('money.refund_requested', $operator, $refund, ['payment_id' => $locked->id, 'amount_minor' => $amountMinor, 'reason' => $reason, 'note' => $note]);

            return $refund;
        });

        return $this->submit($refund);
    }

    /**
     * Send a requested refund to its gateway (or record a manual one).
     */
    public function submit(Refund $refund): Refund
    {
        $payment = $refund->payment;
        $result = $this->gateways->driver($refund->method === 'paystack' ? 'paystack' : $payment->provider)->refund($refund, $payment);

        if ($result->status === GatewayResult::UNAVAILABLE) {
            // Nothing was sent; the request stays "requested" and can be retried.
            $refund->forceFill(['failure_message' => mb_substr((string) $result->message, 0, 255)])->save();

            return $refund;
        }

        if ($result->status === GatewayResult::FAILED) {
            return $this->markFailed($refund, (string) $result->message);
        }

        $refund->forceFill(['provider_refund_id' => $result->providerId, 'status' => Refund::PENDING, 'failure_message' => null])->save();

        // A manual refund is processed the moment the operator records it. A Paystack refund waits for
        // Paystack's own "processed" (in this answer, or later by webhook + fetch).
        return $refund->method === 'manual' || $result->succeeded() ? $this->markProcessed($refund) : $refund->fresh();
    }

    /**
     * Apply what Paystack says about a refund, after fetching it (never from the webhook body alone).
     */
    public function syncFromProvider(Refund $refund): Refund
    {
        if ($refund->provider_refund_id === null || ! $refund->isOpen()) {
            return $refund;
        }

        try {
            $remote = $this->client->fetchRefund($refund->provider_refund_id);
        } catch (PaymentGatewayException) {
            return $refund;
        }

        return $this->applyRemote($refund, $remote);
    }

    public function applyRemote(Refund $refund, PaystackRefund $remote): Refund
    {
        if ($remote->amountMinor !== $refund->amount_minor) {
            $this->issues->raise('refund_failed', "refund-amount:{$refund->id}", "Refund #{$refund->id}: Paystack reports a different amount", [
                'subject_type' => 'refund', 'subject_id' => $refund->id, 'ride_id' => $refund->ride_id, 'amount_minor' => $remote->amountMinor, 'currency' => $refund->currency,
            ]);

            return $refund;
        }

        return match ($remote->status) {
            'processed' => $this->markProcessed($refund),
            'failed', 'needs-attention' => $this->markFailed($refund, 'Paystack could not complete the refund ('.$remote->status.').'),
            'processing' => tap($refund)->forceFill(['status' => Refund::PROCESSING])->save(),
            default => $refund,
        };
    }

    /**
     * The card verification charge of a new card is returned at once.
     */
    public function refundCardSetup(Payment $payment): ?Refund
    {
        if ($payment->purpose !== Payment::PURPOSE_CARD_SETUP || $payment->status !== Payment::PAID) {
            return null;
        }

        $refund = Refund::firstOrCreate(['idempotency_key' => "card-setup-refund:{$payment->id}"], [
            'payment_id' => $payment->id,
            'amount_minor' => $payment->amountMinor(),
            'currency' => $payment->currency,
            'reason' => 'card_setup_verification',
            'method' => 'paystack',
            'status' => Refund::REQUESTED,
        ]);

        if ($refund->status !== Refund::REQUESTED) {
            return $refund;
        }

        $refund = $this->submit($refund);

        if ($refund->status === Refund::REQUESTED || $refund->status === Refund::FAILED) {
            $this->issues->raise('card_setup_refund', "card-setup-refund:{$payment->id}", "Card verification charge of payment #{$payment->id} is not refunded yet", [
                'subject_type' => 'payment', 'subject_id' => $payment->id, 'amount_minor' => $payment->amountMinor(), 'currency' => $payment->currency,
                'details' => ['refund_id' => $refund->id, 'message' => $refund->failure_message],
            ]);
        }

        return $refund;
    }

    private function markProcessed(Refund $refund): Refund
    {
        $posted = DB::transaction(function () use ($refund) {
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Refund::PROCESSED) {
                return false;
            }

            $payment = Payment::whereKey($locked->payment_id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['status' => Refund::PROCESSED, 'processed_at' => now(), 'failure_message' => null])->save();
            $payment->forceFill(['refunded_minor' => (int) $payment->refunded_minor + $locked->amount_minor])->save();

            if ($locked->reason === 'card_setup_verification') {
                $this->ledger->post($this->rules->cardSetupRefund($locked->id, $payment->id, $locked->amount_minor, $locked->currency));

                return false;
            }

            $source = $locked->method === 'paystack' ? Account::GATEWAY_CLEARING : Account::PLATFORM_BANK;
            $this->ledger->post($this->rules->refund($locked->id, $locked->ride_id, $locked->amount_minor, $locked->currency, $source, Refund::REASONS[$locked->reason] ?? $locked->reason));

            if ($locked->charge_back_driver) {
                $this->chargeBack($locked);
            }

            return true;
        });

        $refund = $refund->fresh();

        if ($posted && $refund->ride !== null) {
            $refund->ride->passenger?->notify(new RidePaymentNotification($refund->ride, 'ride_refunded', 'Refund processed',
                Money::format($refund->amount_minor, $refund->currency)." was refunded for ride #{$refund->ride_id}. Card refunds can take a few working days to show on your statement."));
        }

        return $refund;
    }

    /**
     * Recover the refund from the driver: a negative adjustment earning plus its journal. Never more
     * than the driver's net for the trip.
     */
    private function chargeBack(Refund $refund): void
    {
        $tripEarning = DriverEarning::where('source_key', "trip:{$refund->ride_id}")->first();

        if ($tripEarning === null) {
            return;
        }

        $amount = min($refund->amount_minor, max(0, $tripEarning->net_minor));

        if ($amount <= 0) {
            return;
        }

        $account = $tripEarning->payee_type === 'fleet_owner' && $tripEarning->fleet_id !== null
            ? Account::fleetPayable($tripEarning->fleet_id)
            : Account::driverPayable($tripEarning->driver_id);

        $journal = $this->ledger->post($this->rules->refundChargeBack($refund->id, $refund->ride_id, $amount, $refund->currency, $account));

        DriverEarning::firstOrCreate(['source_key' => "refund-charge-back:{$refund->id}"], [
            'kind' => DriverEarning::KIND_ADJUSTMENT,
            'driver_id' => $tripEarning->driver_id,
            'fleet_id' => $tripEarning->fleet_id,
            'payee_type' => $tripEarning->payee_type,
            'payee_user_id' => $tripEarning->payee_user_id,
            'ride_id' => $refund->ride_id,
            'currency' => $refund->currency,
            'net_minor' => -$amount,
            'breakdown' => ['reason' => 'Refund to the passenger recovered: '.(Refund::REASONS[$refund->reason] ?? $refund->reason), 'refund_id' => $refund->id],
            'status' => DriverEarning::AVAILABLE,
            'earned_at' => now(),
            'available_at' => now(),
            'journal_id' => $journal->id,
        ]);
    }

    private function markFailed(Refund $refund, string $message): Refund
    {
        $refund->forceFill(['status' => Refund::FAILED, 'failure_message' => mb_substr($message, 0, 255)])->save();

        if ($refund->reason !== 'card_setup_verification') {
            $this->issues->raise('refund_failed', "refund-failed:{$refund->id}", "Refund #{$refund->id} failed: {$message}", [
                'subject_type' => 'refund', 'subject_id' => $refund->id, 'ride_id' => $refund->ride_id, 'amount_minor' => $refund->amount_minor, 'currency' => $refund->currency,
            ]);
        }

        return $refund;
    }
}
