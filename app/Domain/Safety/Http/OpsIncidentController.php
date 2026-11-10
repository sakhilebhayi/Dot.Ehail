<?php

namespace App\Domain\Safety\Http;

use App\Domain\Safety\IncidentService;
use App\Domain\Safety\IncidentStatus;
use App\Domain\Safety\Models\SafetyIncident;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Operations safety queue (platform operators; route group carries the `operator` middleware).
 * Opening an incident's full context (names, addresses, positions) is audit-logged.
 */
class OpsIncidentController extends Controller
{
    public const RESOLUTION_CODES = ['user_safe', 'false_alarm', 'referred_to_police', 'referred_to_medical', 'driver_actioned', 'passenger_actioned', 'no_contact', 'other'];

    public function __construct(private readonly IncidentService $incidents) {}

    public function queue(Request $request): JsonResponse
    {
        // Polls advance overdue escalations (no daemon on the host; the scheduler also runs it).
        $this->incidents->escalateDue();

        $validated = $request->validate(['status' => ['nullable', Rule::in(['active', ...array_column(IncidentStatus::cases(), 'value')])]]);
        $status = $validated['status'] ?? 'active';

        $incidents = SafetyIncident::query()
            ->when($status === 'active', fn ($q) => $q->whereIn('status', ['open', 'escalated', 'acknowledged']), fn ($q) => $q->where('status', $status))
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderBy('created_at')
            ->paginate(50);

        return response()->json([
            'data' => collect($incidents->items())->map(fn (SafetyIncident $i) => [
                'id' => $i->id,
                'reference' => $i->reference,
                'kind' => $i->kind->value,
                'kind_label' => $i->kind->label(),
                'severity' => $i->severity->value,
                'status' => $i->status->value,
                'source' => $i->source,
                'ride_id' => $i->ride_id,
                'owner_id' => $i->owner_id,
                'escalation_level' => $i->escalation_level,
                'acknowledged' => $i->acknowledged_at !== null,
                'next_escalation_at' => $i->next_escalation_at?->toIso8601String(),
                'minutes_open' => (int) $i->created_at->diffInMinutes(now()),
            ])->values(),
            'meta' => ['total' => $incidents->total(), 'page' => $incidents->currentPage(), 'last_page' => $incidents->lastPage()],
        ]);
    }

    public function show(Request $request, SafetyIncident $incident): JsonResponse
    {
        AuditLog::record('safety_incident.opened', $request->user(), $incident, []);

        return response()->json([
            'data' => $incident->toArray(),
            'timeline' => $incident->events->map(fn ($e) => ['type' => $e->type, 'actor_id' => $e->actor_id, 'data' => $e->data, 'at' => $e->created_at?->toIso8601String()])->values(),
            'what_we_did' => $this->incidents->whatWeDid($incident),
            'resolution_codes' => self::RESOLUTION_CODES,
        ]);
    }

    public function acknowledge(Request $request, SafetyIncident $incident): JsonResponse
    {
        $updated = $this->incidents->acknowledge($incident, $request->user());
        // Ownership changes are audited as well as kept on the incident timeline (R-46 polish, D-2202).
        AuditLog::record('safety_incident.acknowledged', $request->user(), $updated, ['owner_id' => $updated->owner_id, 'escalation_level' => $updated->escalation_level]);

        return response()->json(['data' => $updated->only(['id', 'status', 'owner_id', 'acknowledged_at'])]);
    }

    public function assign(Request $request, SafetyIncident $incident): JsonResponse
    {
        $validated = $request->validate(['owner_id' => ['required', 'integer', 'exists:users,id']]);

        $previous = $incident->owner_id;
        $updated = $this->incidents->assign($incident, User::findOrFail($validated['owner_id']), $request->user());
        AuditLog::record('safety_incident.assigned', $request->user(), $updated, ['owner_id' => $updated->owner_id, 'previous_owner_id' => $previous]);

        return response()->json(['data' => $updated->only(['id', 'status', 'owner_id'])]);
    }

    public function escalate(Request $request, SafetyIncident $incident): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        return response()->json(['data' => $this->incidents->escalate($incident, $request->user(), $validated['reason'] ?? 'manual')->only(['id', 'status', 'escalation_level'])]);
    }

    public function note(Request $request, SafetyIncident $incident): JsonResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $this->incidents->addNote($incident, $request->user(), $validated['note']);

        return response()->json(['recorded' => true], 201);
    }

    public function resolve(Request $request, SafetyIncident $incident): JsonResponse
    {
        $validated = $request->validate([
            'resolution_code' => ['required', Rule::in(self::RESOLUTION_CODES)],
            'note' => ['nullable', 'string', 'max:2000', 'required_if:resolution_code,other'],
        ]);

        return response()->json(['data' => $this->incidents->resolve($incident, $request->user(), $validated['resolution_code'], $validated['note'] ?? null)->only(['id', 'status', 'resolution_code'])]);
    }

    public function close(Request $request, SafetyIncident $incident): JsonResponse
    {
        return response()->json(['data' => $this->incidents->close($incident, $request->user())->only(['id', 'status'])]);
    }
}
