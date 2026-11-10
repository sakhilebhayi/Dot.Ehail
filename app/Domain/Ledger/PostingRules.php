<?php

namespace App\Domain\Ledger;

use App\Domain\Payments\Seams\EarningsSplit;
use App\Domain\Payments\Seams\FareBreakdown;
use InvalidArgumentException;

/**
 * Every journal the money domain posts is built here, so the chart of accounts and the rules live in
 * one file (docs/R46_MONEY.md, "Posting rules", mirrors it). Each method returns a balanced entry or
 * throws; idempotency keys are derived from the business identity of the event.
 *
 * $creditAccount for driver earnings is driver_payable:{driver} or fleet_payable:{fleet}.
 */
class PostingRules
{
    /**
     * A completed trip's fare is recognised the moment it is final, whatever the payment method:
     *   Dr passenger_receivable  payable (gross - promotion)
     *   Dr promotions_expense    promotion
     *   Cr tax_payable           tax
     *   Cr platform_revenue_fees platform fees
     *   Cr platform_revenue_commission  commission + driver deductions
     *   Cr driver/fleet payable  driver net
     */
    public function tripRevenue(int $rideId, FareBreakdown $fare, EarningsSplit $split, string $earningsAccount): JournalEntry
    {
        $this->assertSplitMatches($fare, $split);

        return (new JournalEntry('trip_revenue', "trip-revenue:{$rideId}", $fare->currency, "Trip #{$rideId} completed: fare recognised ({$split->plan})", ['ride_id' => $rideId]))
            ->debit(Account::PASSENGER_RECEIVABLE, $fare->payableMinor())
            ->debit(Account::PROMOTIONS_EXPENSE, min($fare->promotionMinor, $fare->grossMinor))
            ->credit(Account::TAX_PAYABLE, $fare->taxMinor)
            ->credit(Account::PLATFORM_REVENUE_FEES, $fare->platformFeesMinor)
            ->credit(Account::PLATFORM_REVENUE_COMMISSION, $split->commissionMinor + $split->deductionsMinor())
            ->credit($earningsAccount, $split->driverNetMinor);
    }

    /**
     * A passenger's payment settles what they owe. Where the money now sits depends on the method:
     * gateway_clearing (card / checkout), cash_with_driver:{driver} (cash), business_receivable:{id}.
     */
    public function paymentSettled(int $paymentId, ?int $rideId, string $holdingAccount, int $amountMinor, string $currency, string $method): JournalEntry
    {
        return (new JournalEntry('payment_settled', "payment-settled:{$paymentId}", $currency, "Payment #{$paymentId} settled by {$method}".($rideId ? " for trip #{$rideId}" : ''), ['ride_id' => $rideId, 'payment_id' => $paymentId]))
            ->debit($holdingAccount, $amountMinor)
            ->credit(Account::PASSENGER_RECEIVABLE, $amountMinor);
    }

    /**
     * Cancellation fee recognised (the fee is then collected like a fare):
     *   Dr passenger_receivable fee / Cr commission (platform share) / Cr driver payable (driver share)
     */
    public function cancellationFee(int $rideId, int $feeMinor, int $driverShareMinor, string $currency, ?string $earningsAccount): JournalEntry
    {
        if ($driverShareMinor > $feeMinor || ($driverShareMinor > 0 && $earningsAccount === null)) {
            throw new InvalidArgumentException('Driver share of a cancellation fee is invalid.');
        }

        $entry = (new JournalEntry('cancellation_fee', "cancellation-fee:{$rideId}", $currency, "Cancellation fee for ride #{$rideId}", ['ride_id' => $rideId]))
            ->debit(Account::PASSENGER_RECEIVABLE, $feeMinor)
            ->credit(Account::PLATFORM_REVENUE_COMMISSION, $feeMinor - $driverShareMinor);

        return $earningsAccount === null ? $entry : $entry->credit($earningsAccount, $driverShareMinor);
    }

    /**
     * Refund returned to the passenger. Paystack refunds leave gateway_clearing; a manual refund of a
     * cash or business payment leaves the platform bank account. With charge_back_driver the driver's
     * share is recovered from their earnings (a second, separate journal, see refundChargeBack()).
     */
    public function refund(int $refundId, ?int $rideId, int $amountMinor, string $currency, string $sourceAccount, string $reason): JournalEntry
    {
        return (new JournalEntry('refund', "refund:{$refundId}", $currency, "Refund #{$refundId}: {$reason}", ['ride_id' => $rideId, 'refund_id' => $refundId]))
            ->debit(Account::REFUNDS, $amountMinor)
            ->credit($sourceAccount, $amountMinor);
    }

    public function refundChargeBack(int $refundId, ?int $rideId, int $amountMinor, string $currency, string $earningsAccount): JournalEntry
    {
        return (new JournalEntry('refund_charge_back', "refund-charge-back:{$refundId}", $currency, "Refund #{$refundId} recovered from driver earnings", ['ride_id' => $rideId, 'refund_id' => $refundId]))
            ->debit($earningsAccount, $amountMinor)
            ->credit(Account::REFUNDS, $amountMinor);
    }

    /** Card verification charge received (it is refunded straight away). */
    public function cardSetupCharge(int $paymentId, int $amountMinor, string $currency): JournalEntry
    {
        return (new JournalEntry('card_setup_charge', "card-setup:{$paymentId}", $currency, "Card verification charge, payment #{$paymentId}", ['payment_id' => $paymentId]))
            ->debit(Account::GATEWAY_CLEARING, $amountMinor)
            ->credit(Account::CARD_VERIFICATION_CLEARING, $amountMinor);
    }

    public function cardSetupRefund(int $refundId, int $paymentId, int $amountMinor, string $currency): JournalEntry
    {
        return (new JournalEntry('card_setup_refund', "refund:{$refundId}", $currency, "Card verification charge returned, payment #{$paymentId}", ['payment_id' => $paymentId, 'refund_id' => $refundId]))
            ->debit(Account::CARD_VERIFICATION_CLEARING, $amountMinor)
            ->credit(Account::GATEWAY_CLEARING, $amountMinor);
    }

    /** An operator writes off what a passenger owes for a trip. The driver keeps their earnings. */
    public function fareWaived(int $rideId, int $amountMinor, string $currency, string $reason, ?int $operatorId): JournalEntry
    {
        return (new JournalEntry('fare_waived', "fare-waived:{$rideId}", $currency, "Fare for trip #{$rideId} waived: {$reason}", ['ride_id' => $rideId], $operatorId))
            ->debit(Account::WAIVERS_EXPENSE, $amountMinor)
            ->credit(Account::PASSENGER_RECEIVABLE, $amountMinor);
    }

    /** An incentive is earned (rules met): the platform owes the reward. */
    public function incentiveEarned(string $sourceKey, int $rewardMinor, string $currency, string $earningsAccount, string $programName, int $earningId): JournalEntry
    {
        return (new JournalEntry('incentive', "incentive:{$sourceKey}", $currency, "Incentive earned: {$programName}", ['earning_id' => $earningId]))
            ->debit(Account::INCENTIVES_EXPENSE, $rewardMinor)
            ->credit($earningsAccount, $rewardMinor);
    }

    /**
     * A payout is paid: the payee's available earnings are settled (one or more payable accounts, e.g.
     * a fleet owner of two fleets), cash fares their drivers hold are netted against them, and the rest
     * leaves the bank (manual) or the gateway balance (Paystack transfer).
     *
     * @param  array<string, int>  $earningsByAccount  driver_payable:{id} / fleet_payable:{id} => amount
     * @param  array<string, int>  $cashOffsets  cash_with_driver:{id} => amount netted
     */
    public function payoutPaid(int $payoutId, array $earningsByAccount, array $cashOffsets, int $sentMinor, string $currency, string $method): JournalEntry
    {
        if (array_sum($earningsByAccount) !== array_sum($cashOffsets) + $sentMinor) {
            throw new InvalidArgumentException('Payout composition does not add up.');
        }

        $entry = new JournalEntry('payout', "payout:{$payoutId}", $currency, "Payout #{$payoutId} paid ({$method})", ['payout_id' => $payoutId]);
        $this->signedLines($entry, $earningsByAccount, debit: true);
        $this->signedLines($entry, $cashOffsets, debit: false);

        return $entry->credit($method === 'paystack_transfer' ? Account::GATEWAY_CLEARING : Account::PLATFORM_BANK, $sentMinor);
    }

    /**
     * A paid payout came back (bank reversal): the earnings and the cash debt are owed again.
     *
     * @param  array<string, int>  $earningsByAccount
     * @param  array<string, int>  $cashOffsets
     */
    public function payoutReversed(int $payoutId, array $earningsByAccount, array $cashOffsets, int $sentMinor, string $currency, string $method): JournalEntry
    {
        $entry = (new JournalEntry('payout_reversal', "payout-reversal:{$payoutId}", $currency, "Payout #{$payoutId} reversed", ['payout_id' => $payoutId]))
            ->debit($method === 'paystack_transfer' ? Account::GATEWAY_CLEARING : Account::PLATFORM_BANK, $sentMinor);

        $this->signedLines($entry, $cashOffsets, debit: true);
        $this->signedLines($entry, $earningsByAccount, debit: false);

        return $entry;
    }

    /**
     * Amounts per account on one side; a negative amount (an account whose earnings net below zero,
     * e.g. a charge-back) goes on the other side so every line stays positive.
     *
     * @param  array<string, int>  $amounts
     */
    private function signedLines(JournalEntry $entry, array $amounts, bool $debit): void
    {
        foreach ($amounts as $account => $amount) {
            ($amount >= 0) === $debit ? $entry->debit($account, abs($amount)) : $entry->credit($account, abs($amount));
        }
    }

    /**
     * An operator's manual adjustment: any balanced set of lines over the chart of accounts, with a
     * mandatory reason. Lines: [{account, debit_minor, credit_minor}].
     *
     * @param  array<int, array{account: string, debit_minor?: int, credit_minor?: int}>  $lines
     */
    public function adjustment(string $key, string $currency, string $reason, array $lines, int $operatorId, ?int $rideId = null): JournalEntry
    {
        $entry = new JournalEntry('adjustment', "adjustment:{$key}", $currency, $reason, ['ride_id' => $rideId], $operatorId);

        foreach ($lines as $line) {
            $entry->debit($line['account'], (int) ($line['debit_minor'] ?? 0));
            $entry->credit($line['account'], (int) ($line['credit_minor'] ?? 0));
        }

        return $entry;
    }

    private function assertSplitMatches(FareBreakdown $fare, EarningsSplit $split): void
    {
        $parts = $fare->taxMinor + $fare->platformFeesMinor + $split->commissionMinor + $split->deductionsMinor() + $split->driverNetMinor;

        if ($parts !== $fare->grossMinor || min($fare->taxMinor, $fare->platformFeesMinor, $split->commissionMinor, $split->driverNetMinor) < 0) {
            throw new InvalidArgumentException("Earnings split does not add up to the fare ({$parts} vs {$fare->grossMinor}).");
        }
    }
}
