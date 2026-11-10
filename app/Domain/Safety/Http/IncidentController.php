<?php

namespace App\Domain\Safety\Http;

use App\Domain\Safety\IncidentKind;
use App\Domain\Safety\IncidentService;
use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Safety\RideSafety;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rider and driver side of incidents: SOS on a ride (one tap plus a 3-second cancel window in the
 * UI, then this call; idempotent per ride and person), other reports, own incidents, the
 * "what we did" confirmation, location updates and "I'm safe now".
 */
class IncidentController extends Controller
{
    public function __construct(private readonly IncidentService $incidents) {}

    public function sos(Request $request, Ride $ride): JsonResponse
    {
        abort_if(RideSafety::roleOf($ride, $request->user()) === null, 404);

        $validated = $request->validate($this->locationRules());

        $result = $this->incidents->raise($ride, $request->user(), IncidentKind::Sos, [
            'lat' => $validated['lat'] ?? null,
            'lng' => $validated['lng'] ?? null,
            'accuracy_m' => $validated['accuracy_m'] ?? null,
        ]);

        return response()->json([
            'created' => $result['created'],
            'what_we_did' => $this->incidents->whatWeDid($result['incident']),
        ], $result['created'] ? 201 : 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in([IncidentKind::Accident->value, IncidentKind::Harassment->value, IncidentKind::Other->value, IncidentKind::Sos->value])],
            'ride_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:2000'],
        ] + $this->locationRules());

        $ride = isset($validated['ride_id']) ? Ride::find($validated['ride_id']) : null;
        abort_if(isset($validated['ride_id']) && ($ride === null || RideSafety::roleOf($ride, $request->user()) === null), 404);

        $result = $this->incidents->raise($ride, $request->user(), IncidentKind::from($validated['kind']), [
            'description' => $validated['description'] ?? null,
            'lat' => $validated['lat'] ?? null,
            'lng' => $validated['lng'] ?? null,
            'accuracy_m' => $validated['accuracy_m'] ?? null,
        ]);

        return response()->json(['created' => $result['created'], 'what_we_did' => $this->incidents->whatWeDid($result['incident'])], $result['created'] ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $incidents = SafetyIncident::query()->where('reporter_id', $request->user()->id)->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => $incidents->map(fn (SafetyIncident $i) => $this->reporterArray($i))->values()]);
    }

    public function show(Request $request, SafetyIncident $incident): JsonResponse
    {
        abort_unless($incident->reporter_id === $request->user()->id, 404);

        return response()->json(['data' => $this->reporterArray($incident), 'what_we_did' => $this->incidents->whatWeDid($incident)]);
    }

    public function location(Request $request, SafetyIncident $incident): JsonResponse
    {
        abort_unless($incident->reporter_id === $request->user()->id, 404);
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $this->incidents->updateLocation($incident, $request->user(), (float) $validated['lat'], (float) $validated['lng'], $validated['accuracy_m'] ?? null);

        return response()->json(['recorded' => $incident->fresh()->status->isActive()]);
    }

    public function safe(Request $request, SafetyIncident $incident): JsonResponse
    {
        abort_unless($incident->reporter_id === $request->user()->id, 404);
        $this->incidents->markSafe($incident, $request->user());

        return response()->json(['recorded' => true, 'message' => 'Thank you. Operations will still review what happened.']);
    }

    /** @return array<string, list<string>> */
    private function locationRules(): array
    {
        return [
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /** @return array<string, mixed> */
    private function reporterArray(SafetyIncident $incident): array
    {
        return [
            'id' => $incident->id,
            'reference' => $incident->reference,
            'kind' => $incident->kind->value,
            'kind_label' => $incident->kind->label(),
            'status' => $incident->status->value,
            'ride_id' => $incident->ride_id,
            'raised_at' => $incident->created_at->toIso8601String(),
            'acknowledged_at' => $incident->acknowledged_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
        ];
    }
}
