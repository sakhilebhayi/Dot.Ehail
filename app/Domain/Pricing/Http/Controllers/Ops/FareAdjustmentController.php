<?php

namespace App\Domain\Pricing\Http\Controllers\Ops;

use App\Domain\Pricing\Exceptions\FareNotFinalisable;
use App\Domain\Pricing\Finalisation\FareAdjuster;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FareAdjustmentController extends Controller
{
    public function store(Request $request, Ride $ride, FareAdjuster $adjuster): JsonResponse
    {
        $valid = $request->validate([
            'new_total_minor' => ['required', 'integer', 'min:0', 'max:10000000'],
            'reason_code' => ['required', Rule::in(FareAdjuster::REASON_CODES)],
            'text' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $final = $adjuster->adjust($ride, $request->user(), (int) $valid['new_total_minor'], $valid['reason_code'], $valid['text']);
        } catch (FareNotFinalisable $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json(['fare' => $final->toPassengerArray()]);
    }
}
