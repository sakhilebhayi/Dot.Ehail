<?php

namespace App\Domain\Ledger;

use App\Domain\Earnings\Models\Payout;
use App\Domain\Ledger\Models\LedgerJournal;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Money;
use Carbon\CarbonImmutable;

/**
 * Reconciliation for operations:
 *  1. internal: every verified payment, processed refund and paid payout has its journal with the same
 *     amount; every journal balances; total debits equal total credits;
 *  2. gateway: what Paystack settled (the settlement/transactions export the operator downloads from
 *     the Paystack dashboard, columns "reference" and "amount" in rand) against our verified payments.
 * Mismatches can be recorded on the finance exceptions list.
 */
class Reconciliation
{
    public function __construct(private Ledger $ledger, private FinanceIssues $issues) {}

    /**
     * @return array<string, mixed>
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to, bool $recordIssues = false): array
    {
        $currency = Money::currency();
        $mismatches = [];

        $payments = Payment::query()->where('status', Payment::PAID)->whereBetween('paid_at', [$from, $to])->get();
        $journals = LedgerJournal::query()->with('entries')->whereIn('idempotency_key', $payments->flatMap(fn (Payment $p) => ["payment-settled:{$p->id}", "card-setup:{$p->id}"]))->get()->keyBy('idempotency_key');

        foreach ($payments as $payment) {
            $journal = $journals["payment-settled:{$payment->id}"] ?? $journals["card-setup:{$payment->id}"] ?? null;
            $expected = $payment->provider === 'cash' ? (int) (($payment->summary ?? [])['cash_settled_minor'] ?? $payment->amountMinor()) : $payment->amountMinor();

            if ($payment->ride_id === null && $payment->purpose !== Payment::PURPOSE_CARD_SETUP) {
                continue; // unmatched charges are on the desk already
            }

            if ($journal === null) {
                if ($expected > 0) {
                    $mismatches[] = ['kind' => 'payment_not_posted', 'payment_id' => $payment->id, 'reference' => $payment->reference, 'amount_minor' => $expected];
                }

                continue;
            }

            $posted = (int) $journal->entries->sum('debit_minor');

            if ($posted !== $expected) {
                $mismatches[] = ['kind' => 'payment_amount_differs', 'payment_id' => $payment->id, 'reference' => $payment->reference, 'amount_minor' => $expected, 'posted_minor' => $posted];
            }
        }

        foreach (Refund::query()->where('status', Refund::PROCESSED)->whereBetween('processed_at', [$from, $to])->get() as $refund) {
            if (! $this->ledger->hasPosted("refund:{$refund->id}")) {
                $mismatches[] = ['kind' => 'refund_not_posted', 'refund_id' => $refund->id, 'amount_minor' => $refund->amount_minor];
            }
        }

        foreach (Payout::query()->where('status', Payout::PAID)->whereBetween('paid_at', [$from, $to])->get() as $payout) {
            if (! $this->ledger->hasPosted("payout:{$payout->id}")) {
                $mismatches[] = ['kind' => 'payout_not_posted', 'payout_id' => $payout->id, 'amount_minor' => $payout->amount_minor];
            }
        }

        foreach ($this->ledger->unbalancedJournals() as $row) {
            $mismatches[] = ['kind' => 'journal_unbalanced', 'journal_id' => (int) $row->journal_id, 'debit_minor' => (int) $row->d, 'credit_minor' => (int) $row->c];
        }

        $trial = $this->ledger->trialBalance($currency);
        $totalDebits = (int) $trial->sum('debit_minor');
        $totalCredits = (int) $trial->sum('credit_minor');

        if ($recordIssues) {
            foreach ($mismatches as $m) {
                $this->issues->raise($m['kind'] === 'journal_unbalanced' ? 'ledger_imbalance' : 'reconciliation_mismatch',
                    'recon:'.$m['kind'].':'.($m['payment_id'] ?? $m['refund_id'] ?? $m['payout_id'] ?? $m['journal_id']),
                    str_replace('_', ' ', ucfirst($m['kind'])),
                    ['severity' => 'critical', 'amount_minor' => $m['amount_minor'] ?? null, 'currency' => $currency, 'details' => $m]);
            }
        }

        $gateway = $payments->where('provider', 'paystack');

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'currency' => $currency,
            'verified_payments' => [
                'paystack_count' => $gateway->count(),
                'paystack_minor' => (int) $gateway->sum(fn (Payment $p) => $p->amountMinor()),
                'cash_count' => $payments->where('provider', 'cash')->count(),
                'cash_minor' => (int) $payments->where('provider', 'cash')->sum(fn (Payment $p) => (int) (($p->summary ?? [])['cash_settled_minor'] ?? $p->amountMinor())),
            ],
            'ledger' => [
                'total_debits_minor' => $totalDebits,
                'total_credits_minor' => $totalCredits,
                'balanced' => $totalDebits === $totalCredits,
                'gateway_clearing_minor' => $this->ledger->balance(Account::GATEWAY_CLEARING, $currency),
                'passenger_receivable_minor' => $this->ledger->balance(Account::PASSENGER_RECEIVABLE, $currency),
            ],
            'mismatches' => $mismatches,
        ];
    }

    /**
     * Compare a Paystack export (rows with "reference" and "amount" in major units) against our verified
     * Paystack payments.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{matched: int, mismatches: array<int, array<string, mixed>>}
     */
    public function compareSettlement(array $rows, bool $recordIssues = false): array
    {
        $matched = 0;
        $mismatches = [];
        $seen = [];

        foreach ($rows as $row) {
            $reference = trim((string) ($row['reference'] ?? ''));

            if ($reference === '') {
                continue;
            }

            $seen[] = $reference;
            $amount = Money::decimalToMinor((string) ($row['amount'] ?? '0'));
            $payment = Payment::where('reference', $reference)->first();

            if ($payment === null) {
                $mismatches[] = ['kind' => 'settled_but_unknown', 'reference' => $reference, 'amount_minor' => $amount];
            } elseif ($payment->status !== Payment::PAID) {
                $mismatches[] = ['kind' => 'settled_but_not_paid_here', 'reference' => $reference, 'payment_id' => $payment->id, 'status' => $payment->status, 'amount_minor' => $amount];
            } elseif ($payment->amountMinor() !== $amount) {
                $mismatches[] = ['kind' => 'settled_amount_differs', 'reference' => $reference, 'payment_id' => $payment->id, 'amount_minor' => $amount, 'ours_minor' => $payment->amountMinor()];
            } else {
                $matched++;
            }
        }

        if ($recordIssues) {
            foreach ($mismatches as $m) {
                $this->issues->raise('reconciliation_mismatch', 'settlement:'.$m['kind'].':'.$m['reference'], str_replace('_', ' ', ucfirst($m['kind'])).' ('.$m['reference'].')', [
                    'severity' => 'critical', 'amount_minor' => $m['amount_minor'], 'currency' => Money::currency(), 'details' => $m,
                ]);
            }
        }

        return ['matched' => $matched, 'mismatches' => $mismatches];
    }
}
