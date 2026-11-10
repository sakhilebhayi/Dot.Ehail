<?php

namespace App\Domain\Payments;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\PostingRules;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Receipts\ReceiptIssuer;
use App\Domain\Payments\Seams\EarningsSplitSource;
use App\Domain\Payments\Seams\FareBreakdown;
use App\Domain\Payments\Seams\FareSource;
use App\Domain\Payments\Seams\PayeeResolver;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns money events into ledger journals and driver earnings. Every method is idempotent: the
 * journal idempotency key and driver_earnings.source_key make a repeat a no-op.
 */
class Settlement
{
    public function __construct(
        private Ledger $ledger,
        private PostingRules $rules,
        private FareSource $fares,
        private EarningsSplitSource $splitter,
        private PayeeResolver $payees,
    ) {}

    /**
     * Recognise a completed trip's fare and create the driver's (pending) earning. Returns null when
     * the ride has no driver or no fare.
     */
    public function recogniseTrip(Ride $ride): ?DriverEarning
    {
        $existing = DriverEarning::where('source_key', "trip:{$ride->id}")->first();

        if ($existing !== null) {
            return $existing;
        }

        $fare = $this->fares->finalFareFor($ride);
        $driver = $ride->driver_id === null ? null : User::find($ride->driver_id);

        if ($fare === null || $driver === null || $fare->grossMinor <= 0) {
            return null;
        }

        $split = $this->splitter->split($ride, $fare, $driver);
        $payee = $this->payees->payeeFor($driver);
        $earningsAccount = $this->earningsAccount($payee, $driver->id);

        return DB::transaction(function () use ($ride, $fare, $split, $payee, $earningsAccount, $driver) {
            $journal = $this->ledger->post($this->rules->tripRevenue($ride->id, $fare, $split, $earningsAccount));

            try {
                return DB::transaction(fn () => DriverEarning::create([
                    'kind' => DriverEarning::KIND_TRIP,
                    'source_key' => "trip:{$ride->id}",
                    'driver_id' => $driver->id,
                    'fleet_id' => $payee['fleet_id'],
                    'payee_type' => $payee['payee_type'],
                    'payee_user_id' => $payee['payee_user_id'],
                    'ride_id' => $ride->id,
                    'currency' => $fare->currency,
                    'fare_minor' => $fare->grossMinor,
                    'commission_minor' => $split->commissionMinor,
                    'deductions_minor' => $split->deductionsMinor() + $fare->platformFeesMinor + $fare->taxMinor,
                    'net_minor' => $split->driverNetMinor,
                    'estimate_minor' => $fare->estimateMinor,
                    'breakdown' => $this->breakdown($fare, $split),
                    'status' => DriverEarning::PENDING,
                    'earned_at' => $ride->completed_at ?? now(),
                    'journal_id' => $journal->id,
                ]));
            } catch (UniqueConstraintViolationException) {
                return DriverEarning::where('source_key', "trip:{$ride->id}")->firstOrFail();
            }
        });
    }

    /**
     * A payment has been verified (or cash confirmed). Post the settlement journal, make the related
     * earnings available, and issue the receipt.
     */
    public function paymentSucceeded(Payment $payment, ?int $settledMinor = null): void
    {
        $amount = $settledMinor ?? $payment->amountMinor();
        $ride = $payment->ride_id === null ? null : Ride::find($payment->ride_id);

        if ($payment->purpose === Payment::PURPOSE_CARD_SETUP) {
            $this->ledger->post($this->rules->cardSetupCharge($payment->id, $amount, $payment->currency));

            return;
        }

        if ($ride === null) {
            return;
        }

        if ($payment->purpose === Payment::PURPOSE_RIDE_FARE) {
            // A ride completed before this ledger existed is recognised the first time it is paid.
            $this->recogniseTrip($ride);
        }

        $holding = match ($payment->provider) {
            'cash' => Account::cashWithDriver((int) $ride->driver_id),
            'business_account' => Account::of(Account::BUSINESS_RECEIVABLE, (int) ($payment->method?->business_account_id ?? 0)),
            default => Account::GATEWAY_CLEARING,
        };

        if ($amount > 0) {
            $this->ledger->post($this->rules->paymentSettled($payment->id, $ride->id, $holding, $amount, $payment->currency, $payment->provider));
        }

        $sourceKey = $payment->purpose === Payment::PURPOSE_CANCELLATION_FEE ? "cancellation:{$ride->id}" : "trip:{$ride->id}";
        $this->makeAvailable($sourceKey);

        if ($payment->purpose === Payment::PURPOSE_RIDE_FARE || $payment->purpose === Payment::PURPOSE_CANCELLATION_FEE) {
            app(ReceiptIssuer::class)->issueFor($ride, $payment);
        }
    }

    /**
     * An operator wrote the fare off: the receivable is expensed and the driver still earns.
     */
    public function fareWaived(Ride $ride, ?User $operator, string $reason): void
    {
        $earning = $this->recogniseTrip($ride);

        if ($earning === null) {
            return;
        }

        $outstanding = $this->outstandingForRide($ride);

        if ($outstanding > 0) {
            $this->ledger->post($this->rules->fareWaived($ride->id, $outstanding, $earning->currency, $reason, $operator?->id));
        }

        $this->makeAvailable("trip:{$ride->id}");
    }

    /**
     * What the passenger still owes for a ride (receivable debits minus credits on its journals).
     */
    public function outstandingForRide(Ride $ride): int
    {
        $row = DB::table('ledger_entries')
            ->join('ledger_journals', 'ledger_journals.id', '=', 'ledger_entries.journal_id')
            ->where('ledger_journals.ride_id', $ride->id)
            ->where('ledger_entries.account', Account::PASSENGER_RECEIVABLE)
            ->selectRaw('COALESCE(SUM(ledger_entries.debit_minor), 0) as d, COALESCE(SUM(ledger_entries.credit_minor), 0) as c')
            ->first();

        return (int) $row->d - (int) $row->c;
    }

    public function makeAvailable(string $sourceKey): void
    {
        $hold = max(0, (int) config('money.earnings.availability_hold_hours', 0));

        DriverEarning::where('source_key', $sourceKey)
            ->where('status', DriverEarning::PENDING)
            ->update(['status' => DriverEarning::AVAILABLE, 'available_at' => now()->addHours($hold), 'updated_at' => now()]);
    }

    /**
     * @param  array{payee_type: string, payee_user_id: int, fleet_id: ?int}  $payee
     */
    public function earningsAccount(array $payee, int $driverId): string
    {
        return $payee['payee_type'] === 'fleet_owner' && $payee['fleet_id'] !== null
            ? Account::fleetPayable($payee['fleet_id'])
            : Account::driverPayable($driverId);
    }

    /**
     * @return array<string, mixed>
     */
    private function breakdown(FareBreakdown $fare, Seams\EarningsSplit $split): array
    {
        return [
            'plan' => $split->plan,
            'fare_source' => $fare->source,
            'components' => $fare->components,
            'promotion_minor' => $fare->promotionMinor,
            'tax_minor' => $fare->taxMinor,
            'platform_fees_minor' => $fare->platformFeesMinor,
            'deductions' => $split->deductions,
            'estimate_minor' => $fare->estimateMinor,
            'actual_minor' => $fare->grossMinor,
            'difference_minor' => $fare->estimateMinor === null ? null : $fare->grossMinor - $fare->estimateMinor,
            'reasons' => $fare->reasons,
            'distance_m' => $fare->distanceM,
            'duration_s' => $fare->durationS,
        ];
    }
}
