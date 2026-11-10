<?php

namespace App\Domain\Dispatch\Seams;

use App\Domain\Dispatch\Contracts\PaymentMethodResolver;
use App\Domain\Dispatch\Data\PaymentChoice;
use App\Domain\Dispatch\Exceptions\PaymentMethodUnavailable;
use App\Models\User;

/**
 * Default PaymentMethodResolver until the money domain is bound: cash only.
 * (The existing after-the-trip Paystack payment page keeps working; it does
 * not depend on a method chosen at request time.)
 */
class CashOnlyPaymentMethods implements PaymentMethodResolver
{
    public function resolve(User $passenger, ?string $kind, ?string $reference): PaymentChoice
    {
        if ($kind === null || $kind === 'cash') {
            return new PaymentChoice('cash');
        }

        throw new PaymentMethodUnavailable('Only cash can be chosen when requesting a ride right now.');
    }
}
