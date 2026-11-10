<?php

namespace App\Domain\Integration\Dispatch;

use App\Domain\Dispatch\Contracts\PaymentMethodResolver;
use App\Domain\Dispatch\Data\PaymentChoice;
use App\Domain\Dispatch\Exceptions\PaymentMethodUnavailable;
use App\Domain\Payments\Exceptions\PaymentMethodRefused;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\RidePaymentChoice;
use App\Domain\Payments\PaymentMethods;
use App\Models\Ride;
use App\Models\User;
use App\Services\Payments\Paystack\PaystackConfig;

/**
 * How a ride request is paid, from the money domain (D-706).
 *
 *  - "card" + ref: one of the passenger's saved, chargeable cards; charged after the trip.
 *  - "cash": only when the operator enabled cash (and the category allows it, checked once the ride exists).
 *  - "business_account": only when business billing is available (not until the business wave).
 *  - nothing chosen: the passenger's default chargeable card; else pay from the ride page after the trip
 *    ("checkout") when card payments are enabled; else cash when enabled; else the request is refused,
 *    because the platform would have no way to collect the fare.
 *
 * attach() records the choice on ride_payment_choices inside the ride-creation transaction (money's
 * PaymentMethods::chooseForRide re-checks ownership, chargeability and the cash rules).
 */
class MoneyPaymentMethodResolver implements PaymentMethodResolver
{
    public const CHECKOUT = 'checkout';

    public function __construct(private readonly PaymentMethods $methods, private readonly PaystackConfig $paystack) {}

    public function resolve(User $passenger, ?string $kind, ?string $reference): PaymentChoice
    {
        return match ($kind) {
            RidePaymentChoice::CARD => $this->card($passenger, $reference),
            RidePaymentChoice::CASH => config('money.cash.enabled')
                ? new PaymentChoice(RidePaymentChoice::CASH)
                : throw new PaymentMethodUnavailable('Cash is not accepted on Dot.Ehail right now. Choose a card.'),
            RidePaymentChoice::BUSINESS_ACCOUNT => $this->savedOfType($passenger, $reference, PaymentMethod::BUSINESS_ACCOUNT)
                ?? throw new PaymentMethodUnavailable('Business accounts are not available yet.'),
            null => $this->default($passenger),
            default => throw new PaymentMethodUnavailable('Choose card or cash.'),
        };
    }

    public function attach(Ride $ride, User $passenger, PaymentChoice $choice): void
    {
        if ($choice->kind === self::CHECKOUT) {
            return;
        }

        try {
            $this->methods->chooseForRide($ride, $passenger, $choice->kind, $choice->reference !== null ? (int) $choice->reference : null);
        } catch (PaymentMethodRefused $e) {
            throw new PaymentMethodUnavailable($e->getMessage());
        }
    }

    private function default(User $passenger): PaymentChoice
    {
        if ($this->paystack->canTakePayments()) {
            $card = PaymentMethod::query()->where('user_id', $passenger->id)->active()->where('type', PaymentMethod::CARD)
                ->orderByDesc('is_default')->orderByDesc('id')->get()->first(fn (PaymentMethod $m) => $m->isChargeable());

            return $card !== null ? new PaymentChoice(RidePaymentChoice::CARD, (string) $card->id) : new PaymentChoice(self::CHECKOUT);
        }

        if (config('money.cash.enabled')) {
            return new PaymentChoice(RidePaymentChoice::CASH);
        }

        throw new PaymentMethodUnavailable('Rides cannot be paid for right now: card payments and cash are both switched off. Please try again later.');
    }

    private function card(User $passenger, ?string $reference): PaymentChoice
    {
        if (! $this->paystack->canTakePayments()) {
            throw new PaymentMethodUnavailable('Card payments are not enabled right now.');
        }

        $method = $this->savedOfType($passenger, $reference, PaymentMethod::CARD);

        if ($method === null) {
            throw new PaymentMethodUnavailable('Choose one of your saved cards, or add a card first.');
        }

        return $method;
    }

    private function savedOfType(User $passenger, ?string $reference, string $type): ?PaymentChoice
    {
        if ($reference === null || ! ctype_digit($reference)) {
            return null;
        }

        $method = PaymentMethod::query()->where('user_id', $passenger->id)->active()->where('type', $type)->find((int) $reference);

        if ($method === null || ($type === PaymentMethod::CARD && ! $method->isChargeable())) {
            return null;
        }

        return new PaymentChoice($type === PaymentMethod::CARD ? RidePaymentChoice::CARD : RidePaymentChoice::BUSINESS_ACCOUNT, (string) $method->id);
    }
}
