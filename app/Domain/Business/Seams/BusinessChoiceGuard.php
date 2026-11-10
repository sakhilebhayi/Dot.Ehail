<?php

namespace App\Domain\Business\Seams;

use App\Domain\Business\Models\BusinessTrip;
use App\Domain\Payments\Exceptions\PaymentMethodRefused;
use App\Domain\Payments\Models\RidePaymentChoice;
use App\Domain\Payments\Seams\BusinessBilling;

/**
 * A ride can be billed to a business account only if it was booked on it (policy checked, limits
 * reserved at request time). Money's per-ride choice endpoint could otherwise move an existing ride onto
 * the account afterwards; this guard (RidePaymentChoice "saving") refuses that with money's own
 * PaymentMethodRefused, which money's controllers already turn into a 422.
 * Active only when this domain's BusinessBilling is the bound one.
 */
class BusinessChoiceGuard
{
    public function saving(RidePaymentChoice $choice): void
    {
        if ($choice->method !== RidePaymentChoice::BUSINESS_ACCOUNT || ! app(BusinessBilling::class) instanceof AccountBusinessBilling) {
            return;
        }

        $booked = BusinessTrip::query()
            ->where('ride_id', $choice->ride_id)
            ->where('business_account_id', $choice->business_account_id)
            ->whereIn('status', [BusinessTrip::BOOKED, BusinessTrip::CHARGED])
            ->whereHas('booker', fn ($q) => $q->where('payment_method_id', $choice->payment_method_id))
            ->exists();

        if (! $booked) {
            throw new PaymentMethodRefused('Business rides are booked with the business profile when the ride is requested. An existing ride cannot be moved onto a business account.');
        }
    }
}
