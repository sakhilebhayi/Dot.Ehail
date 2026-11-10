<?php

namespace App\Domain\Business\Seams;

use App\Domain\Business\BusinessBookings;
use App\Domain\Business\BusinessRefusal;
use App\Domain\Dispatch\Contracts\PaymentMethodResolver;
use App\Domain\Dispatch\Data\PaymentChoice;
use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Payments\Models\RidePaymentChoice;
use App\Models\Ride;
use App\Models\User;

/**
 * Decorates whatever PaymentMethodResolver is bound (the integration's money resolver) so that a ride
 * requested with payment_method "business_account" is checked against the business rules inside the
 * ride-request transaction. Wired with Container::extend in EhailBusinessServiceProvider, so it wraps the
 * integration binding without editing it. Every other method passes straight through.
 *
 * Business details travel with the request as `business[...]` (purpose, traveller_member_id, guest_name,
 * guest_phone, approval_id) or were saved earlier by POST /business/trips/prepare for the same quote.
 * Refusals answer 422 (403/409 where stated) with error "business_<code>" and a plain message.
 */
class BusinessPaymentMethodResolver implements PaymentMethodResolver
{
    public function __construct(private PaymentMethodResolver $inner, private BusinessBookings $bookings) {}

    public function resolve(User $passenger, ?string $kind, ?string $reference): PaymentChoice
    {
        if ($kind === RidePaymentChoice::BUSINESS_ACCOUNT) {
            try {
                $this->bookings->memberForProfile($passenger, $reference);
            } catch (BusinessRefusal $e) {
                throw new DispatchRefused('business_'.$e->reasonCode, $e->getMessage(), 422);
            }
        }

        return $this->inner->resolve($passenger, $kind, $reference);
    }

    public function attach(Ride $ride, User $passenger, PaymentChoice $choice): void
    {
        if ($choice->kind === RidePaymentChoice::BUSINESS_ACCOUNT) {
            try {
                $this->bookings->attach($ride, $passenger, $choice->reference, $this->businessInput());
            } catch (BusinessRefusal $e) {
                throw new DispatchRefused('business_'.$e->reasonCode, $e->getMessage(), in_array($e->status, [403, 409], true) ? $e->status : 422);
            }
        }

        $this->inner->attach($ride, $passenger, $choice);
    }

    /**
     * The `business` object of the current ride request, when there is one.
     *
     * @return array<string, mixed>
     */
    private function businessInput(): array
    {
        if (! app()->bound('request')) {
            return [];
        }

        $input = request()->input('business');

        if (! is_array($input)) {
            return [];
        }

        $clean = [];

        foreach (['purpose' => 200, 'guest_name' => 120, 'guest_phone' => 30, 'rider_phone' => 30] as $key => $max) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $clean[$key] = mb_substr(trim($input[$key]), 0, $max);
            }
        }

        foreach (['traveller_member_id', 'approval_id'] as $key) {
            if (isset($input[$key]) && ctype_digit((string) $input[$key])) {
                $clean[$key] = (int) $input[$key];
            }
        }

        return $clean;
    }
}
