<?php

namespace App\Domain\Pricing\Http\Controllers;

use App\Domain\Pricing\Engine\FareBreakdown;
use App\Domain\Pricing\Http\Controllers\Concerns\ReadsTripPoints;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Quotes\FareQuoter;
use App\Domain\Pricing\Quotes\QuoteComparison;
use App\Domain\Pricing\Quotes\QuoteRequest;
use App\Domain\Pricing\Quotes\QuoteUnavailable;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bookable quotes for the signed-in passenger. With a category: one signed quote; without: every category offered
 * here now (compare). The booking itself (dispatch) passes the quote id to QuoteBinder.
 */
class QuoteController extends Controller
{
    use ReadsTripPoints;

    public function store(Request $request, FareQuoter $quoter): JsonResponse
    {
        [$pickup, $dropoff, $valid] = $this->tripPoints($request, ['promo_code' => ['sometimes', 'nullable', 'string', 'max:40']]);
        $quoteRequest = new QuoteRequest($pickup, $dropoff, $valid['category'] ?? null, $request->user(), null, $valid['promo_code'] ?? null);

        $result = ($valid['category'] ?? null) !== null ? $quoter->quote($quoteRequest) : $quoter->compare($quoteRequest);

        return response()->json(match (true) {
            $result instanceof QuoteUnavailable => $result->toArray(),
            $result instanceof QuoteComparison => ['available' => true, 'kind' => 'quote'] + $result->toArray(),
            default => $result->toArray(),
        });
    }

    public function show(Request $request, string $quote): JsonResponse
    {
        $model = FareQuote::query()->whereKey($quote)->where('passenger_id', $request->user()->id)->firstOrFail();

        return response()->json([
            'quote_id' => $model->id,
            'category' => $model->category?->toPublicArray(),
            'fare' => FareBreakdown::fromComponentsArray($model->components, $model->currency, $model->demand_multiplier_bp)->toArray(),
            'expires_at' => $model->expires_at->toIso8601String(),
            'expired' => $model->isExpired(),
            'used' => $model->consumed_at !== null,
            'is_example_tariff' => $model->is_example,
            'route' => ['distance_m' => $model->route_distance_m, 'duration_s' => $model->route_duration_s, 'provider' => $model->route_provider, 'kind' => 'road'],
        ]);
    }
}
