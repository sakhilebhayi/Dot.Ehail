<?php

namespace App\Http\Controllers\Ehail\Comms;

use App\Domain\Comms\SmsNumbers;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** A confirmed number for safety SMS (only while an SMS provider is configured). */
class SmsController extends Controller
{
    public function __construct(private readonly SmsNumbers $numbers) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['number' => ['required', 'string', 'max:30']]);

        try {
            $row = $this->numbers->start($request->user(), $data['number']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'sms_refused', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['number' => $row->maskedNumber(), 'awaiting_code' => true]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        try {
            $row = $this->numbers->confirm($request->user(), $data['code']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'code_refused', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['number' => $row->maskedNumber(), 'verified' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->numbers->remove($request->user());

        return response()->json(['removed' => true]);
    }
}
