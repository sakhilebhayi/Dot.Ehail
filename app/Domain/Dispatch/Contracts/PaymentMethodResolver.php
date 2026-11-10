<?php

namespace App\Domain\Dispatch\Contracts;

use App\Domain\Dispatch\Data\PaymentChoice;
use App\Domain\Dispatch\Exceptions\PaymentMethodUnavailable;
use App\Models\Ride;
use App\Models\User;

/**
 * Seam to the money domain (payment_methods, PaymentGateway): turn the
 * passenger's chosen method into a reference the ride stores. The default
 * binding accepts cash only and refuses anything else with a clear message.
 */
interface PaymentMethodResolver
{
    /**
     * @throws PaymentMethodUnavailable
     */
    public function resolve(User $passenger, ?string $kind, ?string $reference): PaymentChoice;

    /**
     * Record the resolved choice against the new ride. Called INSIDE the
     * ride-creation transaction; throw PaymentMethodUnavailable to roll the
     * ride back (D-701).
     *
     * @throws PaymentMethodUnavailable
     */
    public function attach(Ride $ride, User $passenger, PaymentChoice $choice): void;
}
