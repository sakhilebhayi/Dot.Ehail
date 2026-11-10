<?php

namespace App\Domain\Business\Seams;

use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessCharge;
use App\Domain\Business\Models\BusinessTrip;
use App\Domain\Business\SpendLimits;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Seams\BusinessBilling;
use App\Domain\Payments\Seams\BusinessChargeDecision;
use App\Domain\Payments\Seams\FareSource;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Money's BusinessBilling seam, answered by the business domain (bound in EhailBusinessServiceProvider).
 *
 * canCharge: a ride that was booked on the account (it passed the policy and reserved the limits at
 * request time) is always paid, even if the account was suspended since: an active trip is never
 * stranded. A ride that was not booked on the account is never billed to it.
 * record: books the amount as a business charge, turns the trip's reservation into spend and returns
 * the charge reference; money then posts payment-settled (Dr business_receivable:{account}).
 */
class AccountBusinessBilling implements BusinessBilling
{
    public function __construct(private SpendLimits $limits) {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function canCharge(int $businessAccountId, User $traveller, int $amountMinor, string $currency): BusinessChargeDecision
    {
        $account = BusinessAccount::find($businessAccountId);

        if ($account === null) {
            return BusinessChargeDecision::deny('That business account does not exist.');
        }

        if ($currency !== $account->currency) {
            return BusinessChargeDecision::deny("The business account is billed in {$account->currency}.");
        }

        // Only rides booked on the account (policy checked and limits reserved at request time) are billed
        // to it, and those always are: suspension never strands a trip already booked.
        return $this->bookedTripAwaitingCharge($account, $traveller) !== null
            ? BusinessChargeDecision::allow()
            : BusinessChargeDecision::deny('This ride was not booked on the business account.');
    }

    public function record(int $businessAccountId, User $traveller, int $amountMinor, string $currency, int $rideId): string
    {
        $payment = Payment::where('ride_id', $rideId)->where('provider', 'business_account')
            ->whereIn('status', [Payment::PENDING, Payment::PAID])->latest('id')->first();

        if ($payment !== null) {
            $existing = BusinessCharge::where('payment_id', $payment->id)->first();

            if ($existing !== null) {
                return $existing->reference;
            }
        }

        $trip = BusinessTrip::where('ride_id', $rideId)->where('business_account_id', $businessAccountId)->first();
        $kind = $payment?->purpose === Payment::PURPOSE_CANCELLATION_FEE ? BusinessCharge::CANCELLATION_FEE : BusinessCharge::RIDE_FARE;

        $charge = DB::transaction(function () use ($businessAccountId, $traveller, $amountMinor, $currency, $rideId, $payment, $trip, $kind) {
            $charge = BusinessCharge::create([
                'business_account_id' => $businessAccountId,
                'business_trip_id' => $trip?->id,
                'ride_id' => $rideId,
                'payment_id' => $payment?->id,
                'traveller_user_id' => $traveller->id,
                'kind' => $kind,
                'reference' => 'ehb-'.$rideId.'-'.Str::lower(Str::random(12)),
                'amount_minor' => $amountMinor,
                'tax_minor' => $kind === BusinessCharge::RIDE_FARE ? $this->taxFor($rideId, $amountMinor) : 0,
                'currency' => $currency,
                'recorded_at' => now(),
            ]);

            if ($trip !== null) {
                BusinessTrip::whereKey($trip->id)->update([
                    'status' => BusinessTrip::CHARGED,
                    'charged_minor' => DB::raw('charged_minor + '.(int) $amountMinor),
                    'updated_at' => now(),
                ]);
            }

            return $charge;
        });

        if ($trip !== null) {
            $this->limits->commit($trip, $amountMinor);
        }

        return $charge->reference;
    }

    /** The trip on this account whose ride is being charged right now (money opened a business payment for it). */
    private function bookedTripAwaitingCharge(BusinessAccount $account, User $traveller): ?BusinessTrip
    {
        $rideIds = Payment::where('provider', 'business_account')->where('user_id', $traveller->id)
            ->where('status', Payment::PENDING)->pluck('ride_id')->filter()->all();

        if ($rideIds === []) {
            return null;
        }

        return BusinessTrip::where('business_account_id', $account->id)->whereIn('ride_id', $rideIds)
            ->whereIn('status', [BusinessTrip::BOOKED, BusinessTrip::CHARGED, BusinessTrip::RELEASED])->first();
    }

    /** Tax inside the fare (only when pricing's tax mode adds tax), from money's FareSource. */
    private function taxFor(int $rideId, int $amountMinor): int
    {
        $ride = Ride::find($rideId);
        $fare = $ride === null ? null : app(FareSource::class)->finalFareFor($ride);

        if ($fare === null || $fare->taxMinor <= 0) {
            return 0;
        }

        return $amountMinor === $fare->payableMinor() ? $fare->taxMinor : intdiv($fare->taxMinor * $amountMinor, max(1, $fare->payableMinor()));
    }
}
