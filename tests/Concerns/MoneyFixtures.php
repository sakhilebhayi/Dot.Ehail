<?php

namespace Tests\Concerns;

use App\Domain\Earnings\Models\Payout;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\Refund;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;
use App\Services\Ehail\RideLifecycle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Paystack contract fake for the money domain. Request and response shapes follow Paystack's
 * published OpenAPI description (github.com/PaystackOSS/openapi, dist/paystack.yaml):
 * TransactionChargeAuthorization / ChargeAuthorizationResponse, VerifyResponse, RefundCreate /
 * RefundCreateResponse, BankValidateRequest / VerificationValidateAccountResponse,
 * TransferRecipientCreate, TransferInitiate / TransferCreateResponse, TransferVerifyResponse.
 * Nothing leaves the test process: preventStrayRequests fails any other call.
 */
trait MoneyFixtures
{
    use FakesPaystack;

    /** success | failed | throw | 400 | 500 */
    protected string $chargeOutcome = 'success';

    protected string $refundCreateStatus = 'pending';

    protected string $refundFetchStatus = 'processed';

    protected string $transferStatus = 'pending';

    protected string $transferVerifyStatus = 'success';

    protected bool $authorizationReusable = true;

    protected string $cardSignature = 'SIG_visa_4081_fixture';

    protected int $refundSeq = 9000;

    /** charge_authorization calls, including ones that timed out (a thrown fake is not "recorded"). */
    protected int $chargeCalls = 0;

    protected function fakeMoneyPaystack(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'api.paystack.co/transaction/initialize' => function (Request $request) {
                $reference = $request['reference'];

                return Http::response(['status' => true, 'message' => 'Authorization URL created', 'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/'.substr(md5($reference), 0, 10),
                    'access_code' => substr(md5($reference), 0, 10),
                    'reference' => $reference,
                ]]);
            },
            'api.paystack.co/transaction/charge_authorization' => function (Request $request) {
                $this->chargeCalls++;

                return match ($this->chargeOutcome) {
                    'throw' => throw new ConnectionException('cURL error 28: timed out'),
                    '400' => Http::response(['status' => false, 'message' => 'Invalid authorization code'], 400),
                    '500' => Http::response(['status' => false, 'message' => 'Server error'], 500),
                    default => Http::response(['status' => true, 'message' => 'Charge attempted', 'data' => [
                        'amount' => $request['amount'],
                        'currency' => $request['currency'] ?? 'ZAR',
                        'transaction_date' => '2026-11-10T10:00:00.000Z',
                        'status' => $this->chargeOutcome,
                        'reference' => $request['reference'],
                        'domain' => 'test',
                        'metadata' => $request['metadata'],
                        'gateway_response' => $this->chargeOutcome === 'success' ? 'Approved' : 'Declined',
                        'message' => null,
                        'channel' => 'card',
                        'ip_address' => null,
                        'log' => null,
                        'fees' => 100,
                        'authorization' => $this->authorizationBlock(),
                        'customer' => ['id' => 1, 'first_name' => null, 'last_name' => null, 'email' => $request['email'], 'customer_code' => 'CUS_x', 'phone' => null, 'metadata' => null, 'risk_action' => 'default'],
                        'plan' => null,
                        'id' => 5000000 + crc32($request['reference']) % 1000000,
                    ]]),
                };
            },
            'api.paystack.co/transaction/verify/*' => function (Request $request) {
                if ($this->verifyStatus !== 200) {
                    return Http::response(['status' => false, 'message' => 'Transaction reference not found'], $this->verifyStatus);
                }

                $reference = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));
                $payment = Payment::where('reference', $reference)->first();

                if ($payment === null) {
                    return Http::response(['status' => false, 'message' => 'Transaction reference not found'], 400);
                }

                $status = match ($this->chargeOutcome) {
                    'failed' => 'failed',
                    '400' => 'failed',
                    default => 'success',
                };

                return Http::response(['status' => true, 'message' => 'Verification successful', 'data' => array_merge([
                    'id' => 5000000 + crc32($reference) % 1000000,
                    'status' => $status,
                    'reference' => $reference,
                    'amount' => $payment->amount_cents,
                    'currency' => $payment->currency,
                    'channel' => 'card',
                    'paid_at' => '2026-11-10T10:00:00.000Z',
                    'gateway_response' => $status === 'success' ? 'Approved' : 'Declined',
                    'customer' => ['email' => 'someone@example.test'],
                    'authorization' => $this->authorizationBlock(),
                ], $this->verifyOverrides)]);
            },
            'api.paystack.co/refund' => function (Request $request) {
                $id = ++$this->refundSeq;

                return Http::response(['status' => true, 'message' => 'Refund has been queued for processing', 'data' => [
                    'transaction' => ['id' => 1, 'reference' => $request['transaction'], 'amount' => 0, 'currency' => 'ZAR'],
                    'integration' => 1,
                    'deducted_amount' => 0,
                    'channel' => null,
                    'merchant_note' => $request['merchant_note'] ?? '',
                    'customer_note' => '',
                    'status' => $this->refundCreateStatus,
                    'refunded_by' => 'ops@example.test',
                    'expected_at' => '2026-11-20T10:00:00.000Z',
                    'currency' => $request['currency'] ?? 'ZAR',
                    'domain' => 'test',
                    'amount' => $request['amount'],
                    'fully_deducted' => false,
                    'id' => $id,
                ]]);
            },
            'api.paystack.co/refund/*' => function (Request $request) {
                $id = basename(parse_url($request->url(), PHP_URL_PATH));
                $refund = Refund::where('provider_refund_id', $id)->first();

                return Http::response(['status' => true, 'message' => 'Refund retrieved', 'data' => [
                    'id' => (int) $id,
                    'status' => $this->refundFetchStatus,
                    'amount' => $refund?->amount_minor ?? 0,
                    'currency' => 'ZAR',
                    'transaction' => ['reference' => $refund?->payment?->reference],
                ]]);
            },
            'api.paystack.co/bank/validate' => fn () => Http::response(['status' => true, 'message' => 'Personal Account Verification attempted', 'data' => [
                'verified' => true, 'verificationMessage' => 'Account is verified successfully',
                'accountAcceptsDebits' => true, 'accountAcceptsCredits' => true, 'accountOpenForMoreThanThreeMonths' => true,
                'accountHolderMatch' => true, 'accountOpen' => true,
            ]]),
            'api.paystack.co/transferrecipient' => fn (Request $request) => Http::response(['status' => true, 'message' => 'Transfer recipient created successfully', 'data' => [
                'active' => true, 'currency' => $request['currency'], 'name' => $request['name'], 'type' => $request['type'],
                'recipient_code' => 'RCP_fixture'.substr(md5($request['account_number']), 0, 6),
                'details' => ['account_number' => $request['account_number'], 'bank_code' => $request['bank_code']],
            ]]),
            'api.paystack.co/transfer' => fn (Request $request) => Http::response(['status' => true, 'message' => 'Transfer has been queued', 'data' => [
                'transfersessionid' => [], 'transfertrials' => [], 'domain' => 'test', 'amount' => $request['amount'], 'currency' => $request['currency'],
                'reference' => $request['reference'], 'source' => 'balance', 'source_details' => null, 'reason' => $request['reason'],
                'status' => $this->transferStatus, 'failures' => null, 'transfer_code' => 'TRF_'.substr(md5($request['reference']), 0, 8),
                'titan_code' => null, 'transferred_at' => null, 'id' => 1, 'integration' => 1, 'request' => 1, 'recipient' => 1,
                'createdAt' => '2026-11-10T10:00:00.000Z', 'updatedAt' => '2026-11-10T10:00:00.000Z',
            ]]),
            'api.paystack.co/transfer/verify/*' => function (Request $request) {
                $reference = basename(parse_url($request->url(), PHP_URL_PATH));
                $payout = Payout::where('reference', $reference)->first();

                return Http::response(['status' => true, 'message' => 'Transfer retrieved', 'data' => [
                    'reference' => $reference, 'status' => $this->transferVerifyStatus, 'amount' => $payout?->amount_minor ?? 0,
                    'transfer_code' => 'TRF_'.substr(md5($reference), 0, 8), 'currency' => 'ZAR',
                ]]);
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function authorizationBlock(): array
    {
        return [
            'authorization_code' => 'AUTH_fixture_8dfhjjdt', 'bin' => '408408', 'last4' => '4081', 'exp_month' => '12', 'exp_year' => '2030',
            'channel' => 'card', 'card_type' => 'visa ', 'bank' => 'TEST BANK', 'country_code' => 'ZA', 'brand' => 'visa',
            'reusable' => $this->authorizationReusable, 'signature' => $this->cardSignature, 'account_name' => null,
        ];
    }

    protected function savedCard(User $user, array $attributes = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'user_id' => $user->id, 'type' => PaymentMethod::CARD, 'provider' => 'paystack',
            'authorization_code' => 'AUTH_fixture_8dfhjjdt', 'signature' => $this->cardSignature,
            'brand' => 'visa', 'last4' => '4081', 'exp_month' => '12', 'exp_year' => '2030', 'bank' => 'TEST BANK',
            'country_code' => 'ZA', 'reusable' => true, 'is_default' => true, 'status' => 'active',
        ], $attributes));
    }

    /**
     * A ride taken to in_progress, then completed through the real lifecycle (which triggers money).
     */
    protected function completeTrip(?User $passenger = null, ?User $driver = null, string $fare = '125.50', ?callable $beforeComplete = null): Ride
    {
        $driver ??= $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id, 'estimated_fare' => $fare, 'started_at' => now()->subMinutes(20), 'accepted_at' => now()->subMinutes(30)], $passenger);

        if ($beforeComplete !== null) {
            $beforeComplete($ride);
        }

        return app(RideLifecycle::class)->advance($ride, $driver, RideStatus::Completed)->fresh();
    }

    protected function ledgerSumsToZero(): bool
    {
        $row = DB::table('ledger_entries')->selectRaw('COALESCE(SUM(debit_minor),0) d, COALESCE(SUM(credit_minor),0) c')->first();

        return (int) $row->d === (int) $row->c;
    }
}
