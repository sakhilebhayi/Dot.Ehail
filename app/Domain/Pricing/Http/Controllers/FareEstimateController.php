<?php

namespace App\Domain\Pricing\Http\Controllers;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Http\Controllers\Concerns\ReadsTripPoints;
use App\Domain\Pricing\Quotes\FareQuoter;
use App\Domain\Pricing\Quotes\QuoteComparison;
use App\Domain\Pricing\Quotes\QuoteRequest;
use App\Domain\Pricing\Quotes\QuoteUnavailable;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public fare calculator (anonymous allowed, rate-limited by IP). Prices are labelled "estimate", nothing is stored,
 * no promotion applies, and nothing here can be booked.
 */
class FareEstimateController extends Controller
{
    use ReadsTripPoints;

    public function categories(): JsonResponse
    {
        return response()->json([
            'categories' => RideCategory::query()->activeRides()->get()->map(fn (RideCategory $c) => $c->toPublicArray())->values(),
        ]);
    }

    public function store(Request $request, FareQuoter $quoter): JsonResponse
    {
        [$pickup, $dropoff, $valid] = $this->tripPoints($request);
        $quoteRequest = new QuoteRequest($pickup, $dropoff, $valid['category'] ?? null, null, null, null, 'estimate');

        $result = ($valid['category'] ?? null) !== null ? $quoter->estimate($quoteRequest) : $quoter->compare($quoteRequest);

        return response()->json(match (true) {
            $result instanceof QuoteUnavailable => $result->toArray(),
            $result instanceof QuoteComparison => ['available' => true, 'kind' => 'estimate'] + $result->toArray(),
            default => $result->toArray(),
        });
    }
}
