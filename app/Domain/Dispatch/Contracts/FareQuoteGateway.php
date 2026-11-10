<?php

namespace App\Domain\Dispatch\Contracts;

use App\Domain\Dispatch\Data\LockedQuote;
use App\Models\Ride;
use App\Models\User;

/**
 * Seam to the pricing domain's FareQuoter (docs/R46_ARCHITECTURE.md
 * section 3). Dispatch never computes or trusts a client fare: a ride is
 * created only from a quote the server issued, bound to the passenger and
 * not expired.
 *
 * The default binding (Seams\UnavailableFareQuotes) reports "not available"
 * until the pricing domain is merged and bound; ride requests are then
 * refused with an honest 503 rather than priced by guesswork.
 */
interface FareQuoteGateway
{
    public function available(): bool;

    /**
     * The quote if it exists, belongs to $passenger and has not expired; null otherwise.
     */
    public function findForPassenger(string $quoteId, User $passenger): ?LockedQuote;

    /**
     * A fresh quote for the same route and category (scheduled rides at
     * dispatch time); null when pricing cannot quote right now.
     */
    public function requote(Ride $ride): ?LockedQuote;

    /**
     * Lock the quote to the ride being created. Called INSIDE the ride-creation
     * transaction (pricing's QuoteBinder: re-checks signature, expiry, route and
     * promotion, consumes the quote, writes the fare snapshot); throw
     * DispatchRefused to roll the ride back (D-701).
     */
    public function bind(LockedQuote $quote, User $passenger, Ride $ride): void;
}
