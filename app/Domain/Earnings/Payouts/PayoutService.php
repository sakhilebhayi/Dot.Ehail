<?php

namespace App\Domain\Earnings\Payouts;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Earnings\Models\Payout;
use App\Domain\Earnings\Models\PayoutBatch;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\FinanceIssues;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\PostingRules;
use App\Domain\Payments\Exceptions\PaymentMethodRefused;
use App\Domain\Payments\Money;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payout batches (contract: PayoutService). A batch collects every payee's AVAILABLE earnings up to
 * the period end, nets the cash fares their drivers hold (commission owed to the platform), skips
 * payees below the minimum or without a bank account (carried forward, reasons in the batch summary),
 * and reserves the included earnings. Then either:
 *  - manual (always available): export the bank CSV, pay from the operator's bank, mark paid; or
 *  - Paystack Transfers (off unless money.payouts.paystack_transfers and Paystack are configured).
 * The ledger journal is posted only when a payout is PAID; failure releases the earnings into the next
 * batch; a reversal after payment reposts what is owed.
 */
class PayoutService
{
    public function __construct(
        private Ledger $ledger,
        private PostingRules $rules,
        private PayoutAccounts $accounts,
        private FinanceIssues $issues,
        private PaystackConfig $paystack,
        private PaystackClient $client,
    ) {}

    public function transfersEnabled(): bool
    {
        return (bool) config('money.payouts.paystack_transfers') && $this->paystack->canTakePayments();
    }

    public function draftBatch(CarbonImmutable $periodEnd, ?User $operator = null): PayoutBatch
    {
        $currency = Money::currency();
        $cutoff = $periodEnd->endOfDay();

        return DB::transaction(function () use ($periodEnd, $cutoff, $currency, $operator) {
            $batch = PayoutBatch::where('currency', $currency)->whereDate('period_end', $periodEnd->toDateString())->lockForUpdate()->first();

            if ($batch !== null) {
                return $batch;
            }

            $batch = PayoutBatch::create([
                'currency' => $currency,
                'period_end' => $periodEnd->toDateString(),
                'status' => 'draft',
                'method' => $this->transfersEnabled() ? 'paystack_transfer' : 'manual',
                'created_by' => $operator?->id,
            ]);

            $carried = [];
            $minimum = max(0, (int) config('money.payouts.minimum_minor', 0));

            $groups = DriverEarning::query()
                ->where('status', DriverEarning::AVAILABLE)
                ->whereNull('payout_id')
                ->where('currency', $currency)
                ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', $cutoff))
                ->where('earned_at', '<=', $cutoff)
                ->lockForUpdate()
                ->get()
                ->groupBy(fn (DriverEarning $e) => $e->payee_type.':'.$e->payee_user_id);

            foreach ($groups as $key => $earnings) {
                [$payeeType, $payeeId] = explode(':', $key);
                $earningsTotal = (int) $earnings->sum('net_minor');

                if ($earningsTotal <= 0) {
                    $carried[] = ['payee_user_id' => (int) $payeeId, 'reason' => 'nothing_owed', 'earnings_minor' => $earningsTotal];

                    continue;
                }

                $offsets = $this->cashOffsets($earnings->pluck('driver_id')->unique()->all(), $earningsTotal, $currency);
                $sent = $earningsTotal - array_sum($offsets);
                $account = $this->accounts->activeFor((int) $payeeId, $payeeType);

                if ($sent > 0 && $sent < $minimum) {
                    $carried[] = ['payee_user_id' => (int) $payeeId, 'reason' => 'below_minimum', 'amount_minor' => $sent];

                    continue;
                }

                if ($sent > 0 && $account === null) {
                    $carried[] = ['payee_user_id' => (int) $payeeId, 'reason' => 'no_bank_account', 'amount_minor' => $sent];

                    continue;
                }

                // A just-changed account is still in its cooling-off period (R-46 security S-15).
                if ($sent > 0 && $account->payable_from !== null && $account->payable_from->isFuture()) {
                    $carried[] = ['payee_user_id' => (int) $payeeId, 'reason' => 'account_cooling_off', 'amount_minor' => $sent];

                    continue;
                }

                $payout = Payout::create([
                    'payout_batch_id' => $batch->id,
                    'payee_user_id' => (int) $payeeId,
                    'payee_type' => $payeeType,
                    'payout_account_id' => $account?->id,
                    'currency' => $currency,
                    'earnings_minor' => $earningsTotal,
                    'cash_offset_minor' => array_sum($offsets),
                    'amount_minor' => $sent,
                    'composition' => [
                        'earning_ids' => $earnings->pluck('id')->values()->all(),
                        'by_kind' => $earnings->groupBy('kind')->map(fn ($rows) => (int) $rows->sum('net_minor'))->all(),
                        'trips' => $earnings->where('kind', DriverEarning::KIND_TRIP)->count(),
                        'cash_offsets' => $offsets,
                        'earnings_accounts' => $earnings->groupBy(fn (DriverEarning $e) => $this->earningsAccountFor($e))->map(fn ($rows) => (int) $rows->sum('net_minor'))->all(),
                    ],
                    'status' => Payout::SCHEDULED,
                    'reference' => 'ehpo-'.$batch->id.'-'.$payeeId.'-'.Str::lower(Str::random(12)),
                ]);

                DriverEarning::whereIn('id', $earnings->pluck('id'))->update(['payout_id' => $payout->id]);
            }

            $batch->forceFill([
                'payout_count' => $batch->payouts()->count(),
                'total_minor' => (int) $batch->payouts()->sum('amount_minor'),
                'summary' => ['carried_forward' => $carried, 'minimum_minor' => $minimum],
            ])->save();

            if ($operator !== null) {
                AuditLog::record('money.payout_batch_drafted', $operator, $batch, ['payouts' => $batch->payout_count, 'total_minor' => $batch->total_minor]);
            }

            // A payout that is entirely netted against cash owed moves no money: settle it now.
            $batch->payouts()->where('amount_minor', 0)->get()->each(fn (Payout $p) => $this->markPaid($p, $operator, 'netted'));

            return $batch->fresh();
        });
    }

    /**
     * Bank upload file. Contains full account numbers: operator only, every export is audited.
     *
     * @return array<int, array<int, string|int|null>>
     */
    public function exportRows(PayoutBatch $batch, User $operator): array
    {
        $rows = [['reference', 'payee_name', 'bank_name', 'branch_code', 'account_number', 'account_type', 'amount', 'currency', 'statement_reference']];

        $batch->payouts()->with(['payee', 'account'])->where('status', Payout::SCHEDULED)->where('amount_minor', '>', 0)->orderBy('id')->each(function (Payout $p) use (&$rows) {
            $rows[] = [
                $p->reference,
                $p->account?->account_holder ?? $p->payee?->name,
                $p->account?->bank_name,
                $p->account?->bank_code,
                $p->account?->account_number,
                $p->account?->account_type,
                Money::toDecimalString($p->amount_minor),
                $p->currency,
                mb_substr('Ehail payout '.$p->id, 0, 20),
            ];
        });

        $batch->forceFill(['status' => $batch->status === 'draft' ? 'exported' : $batch->status, 'exported_at' => now()])->save();
        AuditLog::record('money.payout_batch_exported', $operator, $batch, ['rows' => count($rows) - 1]);

        return $rows;
    }

    public function markPaid(Payout $payout, ?User $operator, string $how = 'manual'): Payout
    {
        $changed = DB::transaction(function () use ($payout, $how) {
            $locked = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [Payout::SCHEDULED, Payout::PROCESSING], true)) {
                return false;
            }

            $method = $how === 'paystack_transfer' ? 'paystack_transfer' : 'manual';
            $journal = $this->ledger->post($this->rules->payoutPaid(
                $locked->id,
                $locked->composition['earnings_accounts'],
                $locked->composition['cash_offsets'] ?? [],
                $locked->amount_minor,
                $locked->currency,
                $method,
            ));

            $locked->forceFill(['status' => Payout::PAID, 'paid_at' => now(), 'journal_id' => $journal->id, 'failure_reason' => null])->save();
            DriverEarning::where('payout_id', $locked->id)->update(['status' => DriverEarning::PAID]);

            return true;
        });

        if ($changed) {
            if ($operator !== null) {
                AuditLog::record('money.payout_paid', $operator, $payout, ['amount_minor' => $payout->amount_minor, 'how' => $how]);
            }

            $this->refreshBatch($payout->batch);
        }

        return $payout->fresh();
    }

    public function markFailed(Payout $payout, ?User $operator, string $reason): Payout
    {
        DB::transaction(function () use ($payout, $reason) {
            $locked = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [Payout::SCHEDULED, Payout::PROCESSING], true)) {
                throw new PaymentMethodRefused('Only a scheduled or processing payout can fail.');
            }

            $locked->forceFill(['status' => Payout::FAILED, 'failure_reason' => mb_substr($reason, 0, 255)])->save();
            DriverEarning::where('payout_id', $locked->id)->update(['payout_id' => null]);
        });

        if ($operator !== null) {
            AuditLog::record('money.payout_failed', $operator, $payout, ['reason' => $reason]);
        }

        $this->issues->raise('payout_failed', "payout-failed:{$payout->id}", "Payout #{$payout->id} failed: {$reason}", [
            'subject_type' => 'payout', 'subject_id' => $payout->id, 'amount_minor' => $payout->amount_minor, 'currency' => $payout->currency,
        ]);

        $this->refreshBatch($payout->batch);

        return $payout->fresh();
    }

    public function reverse(Payout $payout, ?User $operator, string $reason): Payout
    {
        DB::transaction(function () use ($payout) {
            $locked = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Payout::PAID) {
                throw new PaymentMethodRefused('Only a paid payout can be reversed.');
            }

            $this->ledger->post($this->rules->payoutReversed(
                $locked->id,
                $locked->composition['earnings_accounts'],
                $locked->composition['cash_offsets'] ?? [],
                $locked->amount_minor,
                $locked->currency,
                $locked->provider_transfer_code !== null ? 'paystack_transfer' : 'manual',
            ));

            $locked->forceFill(['status' => Payout::REVERSED])->save();
            DriverEarning::where('payout_id', $locked->id)->update(['status' => DriverEarning::AVAILABLE, 'payout_id' => null]);
        });

        $this->issues->raise('payout_reversed', "payout-reversed:{$payout->id}", "Payout #{$payout->id} was reversed: {$reason}", [
            'severity' => 'critical', 'subject_type' => 'payout', 'subject_id' => $payout->id, 'amount_minor' => $payout->amount_minor, 'currency' => $payout->currency,
        ]);

        if ($operator !== null) {
            AuditLog::record('money.payout_reversed', $operator, $payout, ['reason' => $reason]);
        }

        return $payout->fresh();
    }

    /**
     * Send a batch through Paystack Transfers (only when enabled). Each payout: create the recipient
     * once, then POST /transfer with the payout reference (idempotent at Paystack). Paid only when
     * Paystack's verify says success.
     *
     * @return array{sent: int, failed: int, skipped: int}
     *
     * @throws PaymentMethodRefused
     */
    public function sendTransfers(PayoutBatch $batch, User $operator): array
    {
        if (! $this->transfersEnabled()) {
            throw new PaymentMethodRefused('Automatic transfers are switched off. Export the batch and pay it from the bank.');
        }

        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($batch->payouts()->with('account')->where('status', Payout::SCHEDULED)->where('amount_minor', '>', 0)->get() as $payout) {
            $account = $payout->account;

            // Automatic transfers only reach an account Paystack verified; anything else is paid by hand from the
            // export after the operator checks it (R-46 security S-15).
            if ($account === null || $account->verification_status !== 'verified') {
                $counts['skipped']++;

                continue;
            }

            $claimed = Payout::whereKey($payout->id)->where('status', Payout::SCHEDULED)->update(['status' => Payout::PROCESSING]);

            if ($claimed === 0) {
                continue;
            }

            try {
                if ($account->provider_recipient_code === null) {
                    $account->forceFill(['provider_recipient_code' => $this->client->createTransferRecipient($account->account_holder, $account->account_number, $account->bank_code, $payout->currency)])->save();
                }

                $transfer = $this->client->initiateTransfer($payout->amount_minor, $account->provider_recipient_code, $payout->reference, 'Ehail payout '.$payout->id, $payout->currency);
                $payout->forceFill(['provider_transfer_code' => $transfer->transferCode, 'failure_reason' => $transfer->status === 'otp' ? 'Paystack is waiting for an OTP: disable OTP for API transfers in the Paystack dashboard.' : null])->save();
                $counts['sent']++;

                if ($transfer->status === 'success') {
                    $this->syncTransfer($payout->fresh());
                }
            } catch (PaymentGatewayException $e) {
                if ($e->reason === 'unavailable') {
                    // Unknown outcome: stays processing, verified by reference later (money:sweep).
                    $payout->forceFill(['failure_reason' => 'Paystack did not answer; checking again shortly.'])->save();
                    $counts['sent']++;
                } else {
                    $this->markFailed($payout->fresh(), $operator, $e->getMessage());
                    $counts['failed']++;
                }
            }
        }

        $batch->forceFill(['status' => 'processing'])->save();
        AuditLog::record('money.payout_transfers_sent', $operator, $batch, $counts);

        return $counts;
    }

    /**
     * Apply Paystack's verified transfer status to a payout.
     */
    public function syncTransfer(Payout $payout): Payout
    {
        try {
            $transfer = $this->client->verifyTransfer($payout->reference);
        } catch (PaymentGatewayException) {
            return $payout;
        }

        if ($transfer->amountMinor !== $payout->amount_minor) {
            $this->issues->raise('payout_failed', "payout-amount:{$payout->id}", "Payout #{$payout->id}: Paystack reports a different amount", ['subject_type' => 'payout', 'subject_id' => $payout->id]);

            return $payout;
        }

        return match ($transfer->status) {
            'success' => $this->markPaid($payout, null, 'paystack_transfer'),
            'failed' => $payout->status === Payout::PAID ? $payout : $this->markFailed($payout, null, $transfer->reason ?? 'Transfer failed'),
            'reversed' => $payout->status === Payout::PAID ? $this->reverse($payout, null, $transfer->reason ?? 'Transfer reversed') : $this->markFailed($payout, null, 'Transfer reversed'),
            default => $payout,
        };
    }

    /**
     * Cash fares the drivers hold (commission owed to the platform), netted up to the earnings total.
     * Cash already netted in another open payout is not counted twice.
     *
     * @param  array<int, int>  $driverIds
     * @return array<string, int> cash_with_driver:{id} => amount
     */
    private function cashOffsets(array $driverIds, int $earningsTotal, string $currency): array
    {
        $offsets = [];
        $left = $earningsTotal;

        foreach ($driverIds as $driverId) {
            $account = Account::cashWithDriver((int) $driverId);
            $held = max(0, $this->ledger->balance($account, $currency));
            $reserved = (int) Payout::whereIn('status', [Payout::SCHEDULED, Payout::PROCESSING])->get()
                ->sum(fn (Payout $p) => (int) (($p->composition['cash_offsets'] ?? [])[$account] ?? 0));
            $owed = max(0, $held - $reserved);
            $take = min($owed, $left);

            if ($take > 0) {
                $offsets[$account] = $take;
                $left -= $take;
            }
        }

        return $offsets;
    }

    private function earningsAccountFor(DriverEarning $earning): string
    {
        return $earning->payee_type === 'fleet_owner' && $earning->fleet_id !== null
            ? Account::fleetPayable($earning->fleet_id)
            : Account::driverPayable($earning->driver_id);
    }

    private function refreshBatch(?PayoutBatch $batch): void
    {
        if ($batch === null) {
            return;
        }

        $statuses = $batch->payouts()->pluck('status');

        if ($statuses->isEmpty() || $statuses->contains(fn ($s) => in_array($s, [Payout::SCHEDULED, Payout::PROCESSING], true))) {
            return;
        }

        $batch->forceFill([
            'status' => $statuses->contains(Payout::FAILED) ? 'partially_failed' : 'completed',
            'completed_at' => now(),
        ])->save();
    }
}
