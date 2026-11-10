<?php

namespace App\Domain\Payments;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\PostingRules;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Seams\CancellationFeeSource;
use App\Domain\Payments\Seams\PayeeResolver;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Charges a cancellation fee when the pricing domain's policy (CancellationFeeSource) says one is due.
 * With the default NoCancellationFee binding this never charges anything.
 */
class CancellationFees
{
    public function __construct(
        private CancellationFeeSource $policy,
        private Ledger $ledger,
        private PostingRules $rules,
        private PayeeResolver $payees,
        private Settlement $settlement,
        private ChargeAttempts $charges,
        private RideSettlement $routes,
    ) {}

    public function onRideCancelled(int $rideId, string $actor, CarbonInterface $at): ?Payment
    {
        $ride = Ride::find($rideId);

        if ($ride === null || DriverEarning::where('source_key', "cancellation:{$rideId}")->exists()
            || $this->ledger->hasPosted("cancellation-fee:{$rideId}")) {
            return null;
        }

        $fee = $this->policy->feeFor($ride, $actor, $at);

        if ($fee === null || $fee <= 0) {
            return null;
        }

        [$route, $method] = $this->routes->routeFor($ride);

        if (! in_array($route, ['card', 'business_account'], true)) {
            // No way to charge it automatically: nothing is recognised (a fee nobody can collect is not
            // revenue). The ops desk sees cancellations in the ride list.
            return null;
        }

        $currency = Money::currency();
        $driver = $ride->driver_id === null ? null : User::find($ride->driver_id);
        $driverShare = $driver === null ? 0 : Money::percentOf($fee, (float) config('money.cancellation.driver_share_percent', 80));
        $payee = $driver === null ? null : $this->payees->payeeFor($driver);
        $account = $payee === null ? null : $this->settlement->earningsAccount($payee, $driver->id);

        DB::transaction(function () use ($ride, $fee, $driverShare, $currency, $account, $driver, $payee) {
            $journal = $this->ledger->post($this->rules->cancellationFee($ride->id, $fee, $driverShare, $currency, $account));

            if ($driver !== null && $driverShare > 0) {
                DriverEarning::firstOrCreate(['source_key' => "cancellation:{$ride->id}"], [
                    'kind' => DriverEarning::KIND_CANCELLATION_FEE,
                    'driver_id' => $driver->id,
                    'fleet_id' => $payee['fleet_id'],
                    'payee_type' => $payee['payee_type'],
                    'payee_user_id' => $payee['payee_user_id'],
                    'ride_id' => $ride->id,
                    'currency' => $currency,
                    'fare_minor' => $fee,
                    'commission_minor' => $fee - $driverShare,
                    'net_minor' => $driverShare,
                    'breakdown' => ['reason' => 'Passenger cancelled after you were assigned', 'driver_share_percent' => config('money.cancellation.driver_share_percent')],
                    'status' => DriverEarning::PENDING,
                    'earned_at' => now(),
                    'journal_id' => $journal->id,
                ]);
            }
        });

        return $this->charges->charge($ride, Payment::PURPOSE_CANCELLATION_FEE, $fee, $currency, $route, $method);
    }
}
