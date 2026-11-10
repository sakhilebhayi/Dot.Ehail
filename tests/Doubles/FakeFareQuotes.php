<?php

namespace Tests\Doubles;

use App\Domain\Dispatch\Contracts\FareQuoteGateway;
use App\Domain\Dispatch\Data\LockedQuote;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Test double for the pricing domain's FareQuoter behind the dispatch
 * FareQuoteGateway seam. Quotes live in memory; requote() returns whatever
 * the test set with nextRequoteTotal (null = pricing cannot quote).
 */
class FakeFareQuotes implements FareQuoteGateway
{
    /** @var array<string, LockedQuote> */
    public array $quotes = [];

    public ?int $nextRequoteTotal = null;

    public bool $isAvailable = true;

    public function available(): bool
    {
        return $this->isAvailable;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function issue(User $passenger, array $overrides = []): LockedQuote
    {
        $data = array_merge([
            'id' => 'q_'.Str::lower(Str::random(12)),
            'passengerId' => $passenger->id,
            'category' => 'standard',
            'totalMinor' => 8500,
            'currency' => 'ZAR',
            'expiresAt' => now()->addMinutes(5),
            'pickupLat' => -33.9249, 'pickupLng' => 18.4241, 'pickupAddress' => '10 Kloof Street, Gardens',
            'dropoffLat' => -33.9608, 'dropoffLng' => 18.4747, 'dropoffAddress' => 'Rondebosch Main Road',
            'routeDistanceM' => 7400, 'routeDurationS' => 960,
            'components' => [['code' => 'base', 'label' => 'Base fare', 'amount_minor' => 2500], ['code' => 'distance', 'label' => 'Distance', 'amount_minor' => 6000]],
        ], $overrides);

        return $this->quotes[$data['id']] = new LockedQuote(...$data);
    }

    public function findForPassenger(string $quoteId, User $passenger): ?LockedQuote
    {
        $quote = $this->quotes[$quoteId] ?? null;

        return $quote !== null && $quote->passengerId === $passenger->id && $quote->expiresAt->isFuture() ? $quote : null;
    }

    public function requote(Ride $ride): ?LockedQuote
    {
        if ($this->nextRequoteTotal === null) {
            return null;
        }

        $passenger = User::findOrFail($ride->passenger_id);

        return $this->issue($passenger, ['totalMinor' => $this->nextRequoteTotal, 'category' => $ride->category_code ?? 'standard']);
    }

    public function bind(LockedQuote $quote, User $passenger, Ride $ride): void {}
}
