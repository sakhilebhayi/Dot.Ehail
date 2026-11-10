<?php

namespace App\Http\Controllers\Money;

use App\Domain\Payments\Models\Receipt;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Receipts: the passenger's own (an operator sees any). HTML is the print-friendly receipt of record
 * (no PDF library is installed; the page prints cleanly to PDF from the browser); JSON for the app.
 */
class ReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $receipts = Receipt::where('passenger_id', $request->user()->id)->orderByDesc('issued_at')->paginate(25);

        return response()->json($receipts->through(fn (Receipt $r) => [
            'id' => $r->id,
            'number' => $r->number,
            'kind' => $r->kind,
            'ride_id' => $r->ride_id,
            'total_minor' => $r->total_minor,
            'currency' => $r->currency,
            'issued_at' => $r->issued_at->toIso8601String(),
            'url' => route('money.receipts.show', $r),
        ]));
    }

    public function show(Request $request, Receipt $receipt): View|JsonResponse
    {
        Gate::authorize('money.view-receipt', $receipt);

        if ($request->wantsJson()) {
            return response()->json(['number' => $receipt->number, 'issued_at' => $receipt->issued_at->toIso8601String()] + $receipt->snapshot);
        }

        return view('money.receipt', ['receipt' => $receipt, 's' => $receipt->snapshot]);
    }
}
