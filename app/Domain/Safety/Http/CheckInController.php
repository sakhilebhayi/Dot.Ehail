<?php

namespace App\Domain\Safety\Http;

use App\Domain\Safety\CheckInService;
use App\Domain\Safety\IncidentService;
use App\Domain\Safety\Models\SafetyCheckIn;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** "Are you OK?" prompts: the apps poll for pending ones and answer them. */
class CheckInController extends Controller
{
    public function __construct(private readonly CheckInService $checkIns) {}

    public function index(Request $request): JsonResponse
    {
        // Polling advances overdue prompts too (no daemon on the host; the scheduler also runs it).
        $this->checkIns->sweepUnanswered();

        $pending = SafetyCheckIn::query()->where('user_id', $request->user()->id)->where('status', 'pending')->orderBy('id')->get();

        return response()->json(['data' => $pending->map->toPublicArray()->values()]);
    }

    public function respond(Request $request, SafetyCheckIn $checkIn): JsonResponse
    {
        abort_unless($checkIn->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'answer' => ['required', Rule::in(['ok', 'need_help'])],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        $answered = $this->checkIns->respond($checkIn, $request->user(), $validated['answer'], isset($validated['lat']) ? (float) $validated['lat'] : null, isset($validated['lng']) ? (float) $validated['lng'] : null);

        return response()->json([
            'data' => $answered->toPublicArray(),
            'incident_id' => $answered->safety_incident_id,
            'what_we_did' => $validated['answer'] === 'need_help' && $answered->incident ? app(IncidentService::class)->whatWeDid($answered->incident) : null,
        ]);
    }
}
