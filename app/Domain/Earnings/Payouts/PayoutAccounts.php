<?php

namespace App\Domain\Earnings\Payouts;

use App\Domain\Earnings\Models\PayoutAccount;
use App\Domain\Ledger\FinanceIssues;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SecurityNoticeNotification;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackConfig;
use Illuminate\Support\Facades\DB;

/**
 * Bank accounts payouts are sent to. One active account per payee. The account number and holder are
 * encrypted; the blind index flags one account number used by two different payees (a fraud signal)
 * on the finance exceptions list. Verification uses Paystack's /bank/validate (South Africa) when
 * Paystack is configured; otherwise the account is "unverified" and the operator checks it.
 */
class PayoutAccounts
{
    public function __construct(
        private PaystackConfig $config,
        private PaystackClient $client,
        private FinanceIssues $issues,
    ) {}

    /**
     * @param  array{payee_type: string, fleet_id: ?int, account_holder: string, account_number: string, bank_code: string, bank_name: string, account_type: string}  $data
     */
    public function add(User $user, array $data): PayoutAccount
    {
        $number = preg_replace('/\D/', '', $data['account_number']);
        $index = PayoutAccount::blindIndex($number, $data['bank_code']);

        $isChange = PayoutAccount::where('user_id', $user->id)->where('payee_type', $data['payee_type'])->exists();

        $account = DB::transaction(function () use ($user, $data, $number, $index, $isChange) {
            PayoutAccount::where('user_id', $user->id)->where('payee_type', $data['payee_type'])->update(['is_active' => false]);

            return PayoutAccount::create([
                'user_id' => $user->id,
                'payee_type' => $data['payee_type'],
                'fleet_id' => $data['fleet_id'],
                'account_holder' => trim($data['account_holder']),
                'account_number' => $number,
                'account_number_index' => $index,
                'account_last4' => substr($number, -4),
                'bank_code' => trim($data['bank_code']),
                'bank_name' => trim($data['bank_name']),
                'account_type' => $data['account_type'],
                'verification_status' => 'unverified',
                'is_active' => true,
                // A replaced account waits before it receives money (R-46 security S-15); a first account does not.
                'payable_from' => $isChange ? now()->addHours(max(0, (int) config('ehail_security.payout_account_cooling_off_hours', 72))) : null,
            ]);
        });

        // The holder hears about every bank account change by email and in the app, at once.
        $user->notify(new SecurityNoticeNotification(
            'payout_account',
            $isChange ? 'Payout bank account changed' : 'Payout bank account added',
            ($isChange ? 'Your payouts now go to ' : 'Payouts will go to ').$account->bank_name.' account ending '.$account->account_last4.'.'
                .($account->payable_from !== null ? ' For your protection, nothing is paid to it until '.$account->payable_from->timezone('Africa/Johannesburg')->format('j M Y H:i').'.' : ''),
            route('drive.earnings.payouts'),
        ));

        AuditLog::record('money.payout_account_added', $user, $account, ['bank' => $account->bank_name, 'last4' => $account->account_last4]);

        $others = PayoutAccount::where('account_number_index', $index)->where('bank_code', $account->bank_code)->where('user_id', '!=', $user->id)->pluck('user_id')->unique()->values();

        if ($others->isNotEmpty()) {
            $this->issues->raise('duplicate_payout_account', "dup-account:{$index}", 'A bank account was added that another payee already uses', [
                'severity' => 'critical', 'subject_type' => 'payout_account', 'subject_id' => $account->id,
                'details' => ['payee_user_ids' => $others->push($user->id)->all(), 'last4' => $account->account_last4],
            ]);
        }

        return $account;
    }

    /**
     * Verify with Paystack. The identity (or registration) number is used for this request only and
     * never stored.
     */
    public function verify(PayoutAccount $account, string $documentType, string $documentNumber): PayoutAccount
    {
        if (! config('money.payouts.verify_accounts') || ! $this->config->canTakePayments()) {
            $account->forceFill(['verification_status' => 'unavailable', 'verification_message' => 'Automatic verification is not configured; the operator checks this account before paying.'])->save();

            return $account;
        }

        try {
            $result = $this->client->validateBankAccount(
                $account->account_holder,
                $account->account_number,
                $account->account_type,
                $account->bank_code,
                'ZA',
                $documentType,
                $documentNumber,
            );
        } catch (PaymentGatewayException $e) {
            $account->forceFill(['verification_status' => $e->reason === 'unavailable' ? 'unverified' : 'failed', 'verification_message' => mb_substr($e->getMessage(), 0, 255)])->save();

            return $account;
        }

        $account->forceFill([
            'verification_status' => $result['verified'] ? 'verified' : 'failed',
            'verification_message' => $result['message'] ?: null,
            'verified_at' => $result['verified'] ? now() : null,
        ])->save();

        return $account;
    }

    public function activeFor(int $userId, string $payeeType): ?PayoutAccount
    {
        return PayoutAccount::where('user_id', $userId)->where('payee_type', $payeeType)->where('is_active', true)->latest('id')->first();
    }
}
