<?php

namespace App\Domain\Dispatch\Seams;

use App\Domain\Dispatch\Contracts\FareQuoteGateway;
use App\Domain\Dispatch\Data\LockedQuote;
use App\Models\Ride;
use App\Models\User;

/**
 * Default FareQuoteGateway before the pricing domain is bound: no quotes
 * exist, so quote-based ride requests are refused honestly (503
 * pricing_unavailable). Nothing is priced by guesswork.
 */
class UnavailableFareQuotes implements FareQuoteGateway
{
    public function available(): bool
    {
        return false;
    }

    public function findForPassenger(string $quoteId, User $passenger): ?LockedQuote
    {
        return null;
    }

    public function requote(Ride $ride): ?LockedQuote
    {
        return null;
    }
}
