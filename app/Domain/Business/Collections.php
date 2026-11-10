<?php

namespace App\Domain\Business;

use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessPayment;
use App\Domain\Business\Models\BusinessStatement;
use App\Domain\Business\Notifications\StatementIssuedNotification;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\Ledger;
use App\Domain\Payments\Models\PaymentMethod;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\User;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Money coming in from businesses, and what happens when it does not.
 *
 *  - EFT: an operator records the amount received; ledger "business-payment:{id}":
 *    Dr platform_bank, Cr business_receivable:{account}.
 *  - Company card (billing mode "card"): when a statement is issued, the amount owed is charged to the
 *    saved card (an account admin's Paystack authorisation) and counted as paid only when Paystack's
 *    verify says success for our reference, amount and currency; ledger Dr gateway_clearing.
 *  - Dunning (daily): a statement past its due date becomes overdue (email to the billing address);
 *    past due + grace days the account is suspended for NEW rides (trips already booked still complete
 *    and are billed). Paying it reactivates an account that was suspended for being overdue.
 */
class Collections
{
    public function __construct(
        private Ledger $ledger,
        private Statements $statements,
        private BusinessAccounts $accounts,
        private PaystackConfig $paystack,
        private PaystackClient $client,
    ) {}

    /**
     * @throws BusinessRefusal
     */
    public function recordEft(BusinessAccount $account, User $operator, int $amountMinor, string $reference, ?string $note): BusinessPayment
    {
        if ($amountMinor <= 0) {
            throw BusinessRefusal::one('amount_invalid', 'Enter the amount received.');
        }

        if (BusinessPayment::where('reference', $reference)->exists()) {
            throw BusinessRefusal::one('reference_used', 'A payment with that reference is already recorded.', 409);
        }

        $payment = DB::transaction(function () use ($account, $operator, $amountMinor, $reference, $note) {
            $payment = BusinessPayment::create([
                'business_account_id' => $account->id,
                'method' => BusinessPayment::EFT,
                'reference' => $reference,
                'amount_minor' => $amountMinor,
                'currency' => $account->currency,
                'status' => BusinessPayment::PAID,
                'recorded_by' => $operator->id,
                'note' => $note,
                'paid_at' => now(),
            ]);

            $this->post($payment, Account::PLATFORM_BANK, 'EFT received '.$reference, $operator->id);

            return $payment;
        });

        $this->settleStatements($account);

        return $payment;
    }

    /**
     * Charge the company card for what a statement still owes. Safe to call repeatedly: a pending
     * attempt is verified, never charged twice; a new attempt gets a new reference.
     */
    public function collectByCard(BusinessStatement $statement): ?BusinessPayment
    {
        $account = $statement->account;
        $owed = $this->statements->owed($statement);

        if ($owed <= 0 || $account->billing_mode !== BusinessAccount::CARD || ! $this->paystack->canTakePayments()) {
            return null;
        }

        $pending = BusinessPayment::where('statement_id', $statement->id)->where('method', BusinessPayment::CARD)->where('status', BusinessPayment::PENDING)->latest('id')->first();

        if ($pending !== null) {
            return $this->verify($pending);
        }

        if (BusinessPayment::where('statement_id', $statement->id)->where('method', BusinessPayment::CARD)->where('created_at', '>=', now()->subHours(20))->exists()) {
            return null; // one attempt a day
        }

        $card = $account->cardMethod;

        if ($card === null || ! $card->isChargeable() || $card->type !== PaymentMethod::CARD) {
            $this->cardProblem($account, 'There is no chargeable company card on the account.');

            return null;
        }

        $attempt = (int) BusinessPayment::where('statement_id', $statement->id)->where('method', BusinessPayment::CARD)->max('attempt') + 1;
        $payment = BusinessPayment::create([
            'business_account_id' => $account->id,
            'statement_id' => $statement->id,
            'method' => BusinessPayment::CARD,
            'reference' => 'ehail-biz-'.$statement->id.'-'.$attempt.'-'.Str::lower(Str::random(8)),
            'amount_minor' => $owed,
            'currency' => $statement->currency,
            'status' => BusinessPayment::PENDING,
            'attempt' => $attempt,
        ]);

        try {
            $this->client->chargeAuthorization((string) $card->user?->email, $owed, $statement->currency, (string) $card->authorization_code, $payment->reference,
                ['business_statement_id' => $statement->id, 'business_payment_id' => $payment->id, 'purpose' => 'business_statement']);
        } catch (PaymentGatewayException) {
            // Unknown outcome: stays pending; verify decides on the next run.
            return $payment;
        }

        return $this->verify($payment);
    }

    /**
     * Ask Paystack what happened to a pending card collection.
     */
    public function verify(BusinessPayment $payment): BusinessPayment
    {
        try {
            $tx = $this->client->verifyTransaction($payment->reference);
        } catch (PaymentGatewayException) {
            if ($payment->created_at->lt(now()->subMinutes(10))) {
                $payment->forceFill(['status' => BusinessPayment::FAILED, 'failure_message' => 'Paystack has no record of this charge.'])->save();
                $this->cardProblem($payment->account, 'The company card charge could not be confirmed.');
            }

            return $payment;
        }

        if ($tx->isSuccessful() && $tx->reference === $payment->reference && $tx->amountCents === (int) $payment->amount_minor && strtoupper($tx->currency) === $payment->currency) {
            DB::transaction(function () use ($payment) {
                $locked = BusinessPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === BusinessPayment::PAID) {
                    return;
                }

                $locked->forceFill(['status' => BusinessPayment::PAID, 'paid_at' => now(), 'failure_message' => null])->save();
                $this->post($locked, Account::GATEWAY_CLEARING, 'Company card charge '.$locked->reference, null);
            });

            $this->settleStatements($payment->account);

            return $payment->fresh();
        }

        if (in_array($tx->status, ['failed', 'abandoned', 'reversed'], true)) {
            $payment->forceFill(['status' => BusinessPayment::FAILED, 'failure_message' => mb_substr((string) ($tx->gatewayResponse ?? 'The card was declined.'), 0, 300)])->save();
            $this->cardProblem($payment->account, 'The company card was declined.');
        }

        return $payment->fresh();
    }

    /**
     * Daily: overdue marking, reminders, suspension after grace, card retries, paid statements.
     *
     * @return array{overdue: int, suspended: int, paid: int, card_attempts: int}
     */
    public function dunning(): array
    {
        $counts = ['overdue' => 0, 'suspended' => 0, 'paid' => 0, 'card_attempts' => 0];

        $open = BusinessStatement::with('account')->whereIn('status', [BusinessStatement::ISSUED, BusinessStatement::OVERDUE])->orderBy('period')->get();

        foreach ($open as $statement) {
            if ($this->statements->owed($statement) <= 0) {
                $statement->forceFill(['status' => BusinessStatement::PAID, 'paid_at' => now()])->save();
                $counts['paid']++;

                continue;
            }

            if ($statement->account->billing_mode === BusinessAccount::CARD && $this->paystack->canTakePayments()) {
                if ($this->collectByCard($statement) !== null) {
                    $counts['card_attempts']++;
                }

                if ($this->statements->owed($statement) <= 0) {
                    $statement->forceFill(['status' => BusinessStatement::PAID, 'paid_at' => now()])->save();
                    $counts['paid']++;

                    continue;
                }
            }

            if ($statement->status === BusinessStatement::ISSUED && $statement->due_at->isPast()) {
                $statement->forceFill(['status' => BusinessStatement::OVERDUE, 'reminded_at' => now()])->save();
                Notification::route('mail', $statement->account->billing_email)->notify(new StatementIssuedNotification($statement, overdue: true));
                $counts['overdue']++;
            }

            if ($statement->status === BusinessStatement::OVERDUE
                && $statement->due_at->copy()->addDays((int) $statement->account->grace_days)->isPast()
                && $statement->account->status === BusinessAccount::ACTIVE) {
                $this->accounts->suspend($statement->account, 'overdue', 'Statement '.$statement->number.' is overdue.');
                $counts['suspended']++;
            }
        }

        foreach (BusinessAccount::where('status', BusinessAccount::SUSPENDED)->where('status_reason', 'overdue')->get() as $account) {
            $this->reactivateIfClear($account);
        }

        return $counts;
    }

    /**
     * Mark statements paid once payments cover them, and lift an overdue suspension when nothing is overdue.
     */
    public function settleStatements(BusinessAccount $account): void
    {
        foreach (BusinessStatement::where('business_account_id', $account->id)->whereIn('status', [BusinessStatement::ISSUED, BusinessStatement::OVERDUE])->get() as $statement) {
            if ($this->statements->owed($statement) <= 0) {
                $statement->forceFill(['status' => BusinessStatement::PAID, 'paid_at' => now()])->save();
            }
        }

        $this->reactivateIfClear($account->fresh());
    }

    private function reactivateIfClear(BusinessAccount $account): void
    {
        if (in_array($account->status_reason, ['overdue', 'card_failed'], true) && $account->status === BusinessAccount::SUSPENDED
            && ! BusinessStatement::where('business_account_id', $account->id)->where('status', BusinessStatement::OVERDUE)->exists()) {
            $this->accounts->reactivate($account, 'Overdue balance settled.');
        }
    }

    private function cardProblem(BusinessAccount $account, string $message): void
    {
        $account->forceFill(['status_note' => $message])->save();
    }

    private function post(BusinessPayment $payment, string $debitAccount, string $reason, ?int $postedBy): void
    {
        $this->ledger->post((new JournalEntry('business_payment', 'business-payment:'.$payment->id, $payment->currency, $reason, [], $postedBy))
            ->debit($debitAccount, (int) $payment->amount_minor)
            ->credit(Account::of(Account::BUSINESS_RECEIVABLE, $payment->business_account_id), (int) $payment->amount_minor));
    }
}
