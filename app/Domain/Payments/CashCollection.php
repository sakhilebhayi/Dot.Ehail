<?php

namespace App\Domain\Payments;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Ledger\FinanceIssues;
use App\Domain\Payments\Exceptions\PaymentMethodRefused;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Seams\SupportCaseOpener;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The driver confirms the cash they received for a trip. The ledger records what the driver says
 * they hold (cash_with_driver), up to the fare. Less than the fare opens a discrepancy for support:
 * the passenger is not blocked on the driver's word, the shortfall stays receivable until an
 * operator resolves it. More than the fare is the driver's (change or tip) and is not platform money.
 */
class CashCollection
{
    public function __construct(
        private Settlement $settlement,
        private FinanceIssues $issues,
        private SupportCaseOpener $support,
    ) {}

    /**
     * @throws PaymentMethodRefused
     */
    public function confirm(Ride $ride, User $driver, int $receivedMinor): Payment
    {
        if ($ride->driver_id !== $driver->id) {
            throw new PaymentMethodRefused('Only the driver of this trip can confirm the cash.');
        }

        if ($receivedMinor < 0) {
            throw new PaymentMethodRefused('The amount cannot be negative.');
        }

        $short = 0;
        $justConfirmed = false;

        $payment = DB::transaction(function () use ($ride, $driver, $receivedMinor, &$short, &$justConfirmed) {
            Ride::whereKey($ride->id)->lockForUpdate()->first();
            $payment = Payment::where('ride_id', $ride->id)->where('purpose', Payment::PURPOSE_RIDE_FARE)
                ->where('provider', 'cash')->latest('id')->lockForUpdate()->first();

            if ($payment === null) {
                throw new PaymentMethodRefused('This trip is not a cash trip.');
            }

            if ($payment->status === Payment::PAID) {
                return $payment;
            }

            $expected = $payment->amountMinor();
            $settled = min($receivedMinor, $expected);
            $short = $expected - $settled;

            $payment->forceFill([
                'status' => Payment::PAID,
                'paid_at' => now(),
                'confirmed_by' => $driver->id,
                'channel' => 'cash',
                'summary' => array_merge((array) $payment->summary, [
                    'cash_received_minor' => $receivedMinor,
                    'cash_expected_minor' => $expected,
                    'cash_settled_minor' => $settled,
                ]),
            ])->save();

            $ride->forceFill(['payment_status' => PaymentStatus::Paid->value])->save();

            AuditLog::record('money.cash_confirmed', $driver, $ride, ['expected_minor' => $expected, 'received_minor' => $receivedMinor]);

            $justConfirmed = true;

            return $payment;
        });

        if (! $justConfirmed) {
            return $payment;
        }

        $settled = min($receivedMinor, $payment->amountMinor());
        $this->settlement->paymentSucceeded($payment->fresh(), $settled);

        DriverEarning::where('source_key', "trip:{$ride->id}")->update(['cash_collected_minor' => $receivedMinor]);

        if ($short > (int) config('money.cash.discrepancy_tolerance_minor', 0)) {
            // The trip earning waits for an operator: otherwise a driver who reports less cash than they took is
            // paid their full share by bank and keeps the cash (R-46 security S-13). Resolving the issue releases it.
            DriverEarning::where('source_key', "trip:{$ride->id}")->where('status', DriverEarning::AVAILABLE)
                ->update(['status' => DriverEarning::PENDING, 'available_at' => null, 'updated_at' => now()]);

            $summary = "Ride #{$ride->id}: driver confirmed ".Money::format($receivedMinor, $payment->currency).' cash for a '.Money::format($payment->amountMinor(), $payment->currency).' fare';
            $caseRef = $this->support->open('cash_discrepancy', $ride, $driver, $summary);

            $this->issues->raise('cash_discrepancy', "cash-discrepancy:{$payment->id}", $summary, [
                'subject_type' => 'payment', 'subject_id' => $payment->id, 'ride_id' => $ride->id,
                'amount_minor' => $short, 'currency' => $payment->currency,
                'details' => ['expected_minor' => $payment->amountMinor(), 'received_minor' => $receivedMinor, 'support_case' => $caseRef],
            ]);
        }

        return $payment->fresh();
    }
}
