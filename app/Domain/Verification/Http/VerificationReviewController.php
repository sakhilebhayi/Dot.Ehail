<?php

namespace App\Domain\Verification\Http;

use App\Domain\Verification\DocumentStatus;
use App\Domain\Verification\Models\VerificationDocument;
use App\Domain\Verification\VerificationService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Operations review queue for driver and vehicle documents (platform operators only; the route
 * group carries the `operator` middleware and every action re-checks the policy).
 */
class VerificationReviewController extends Controller
{
    public function __construct(private readonly VerificationService $verification) {}

    public function queue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_column(DocumentStatus::cases(), 'value'))],
            'assigned' => ['nullable', Rule::in(['me', 'unassigned', 'any'])],
            'type' => ['nullable', 'string', 'max:40'],
        ]);

        $documents = VerificationDocument::query()
            ->with(['owner:id,name', 'vehicle:id,make,model,color,plate_number,type'])
            ->where('status', $validated['status'] ?? DocumentStatus::Pending->value)
            ->when(($validated['assigned'] ?? 'any') === 'me', fn ($q) => $q->where('assigned_reviewer_id', $request->user()->id))
            ->when(($validated['assigned'] ?? 'any') === 'unassigned', fn ($q) => $q->whereNull('assigned_reviewer_id'))
            ->when(isset($validated['type']), fn ($q) => $q->where('type', $validated['type']))
            ->orderBy('submitted_at')
            ->paginate(50);

        return response()->json([
            'data' => collect($documents->items())->map(fn (VerificationDocument $d) => $d->toPublicArray() + [
                'driver' => ['user_id' => $d->user_id, 'name' => $d->owner?->name],
                'vehicle' => $d->vehicle ? ['id' => $d->vehicle->id, 'description' => trim("{$d->vehicle->color} {$d->vehicle->make} {$d->vehicle->model}"), 'plate' => $d->vehicle->plate_number, 'category' => $d->vehicle->type] : null,
                'assigned_reviewer_id' => $d->assigned_reviewer_id,
                'waiting_minutes' => (int) $d->submitted_at->diffInMinutes(now()),
            ])->values(),
            'meta' => ['total' => $documents->total(), 'page' => $documents->currentPage(), 'last_page' => $documents->lastPage()],
            'reject_reasons' => config('ehail_safety.verification.reject_reasons'),
        ]);
    }

    public function show(VerificationDocument $document): JsonResponse
    {
        Gate::authorize('review', $document);

        $document->load(['reviews', 'owner:id,name', 'vehicle']);
        $profile = $document->driverProfile;

        return response()->json([
            'data' => $document->toPublicArray() + ['assigned_reviewer_id' => $document->assigned_reviewer_id],
            'driver' => ['user_id' => $document->user_id, 'name' => $document->owner?->name, 'profile_status' => $profile?->status],
            'driver_verification' => $profile ? $this->verification->driverStatus($profile) : null,
            'vehicle_verification' => $document->vehicle ? $this->verification->vehicleStatus($document->vehicle) : null,
            'history' => $document->reviews->map(fn ($r) => [
                'action' => $r->action, 'from_status' => $r->from_status, 'to_status' => $r->to_status,
                'reason' => $r->reason, 'note' => $r->note, 'actor_id' => $r->actor_id, 'at' => $r->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function assign(Request $request, VerificationDocument $document): JsonResponse
    {
        Gate::authorize('review', $document);
        $validated = $request->validate(['reviewer_id' => ['nullable', 'integer', 'exists:users,id']]);
        $reviewer = isset($validated['reviewer_id']) ? User::findOrFail($validated['reviewer_id']) : $request->user();

        return response()->json(['data' => $this->verification->assign($document, $reviewer, $request->user())->toPublicArray()]);
    }

    public function approve(Request $request, VerificationDocument $document): JsonResponse
    {
        Gate::authorize('review', $document);
        $validated = $request->validate([
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->verification->approve($document, $request->user(), $validated['expires_on'] ?? null, $validated['note'] ?? null)->toPublicArray()]);
    }

    public function reject(Request $request, VerificationDocument $document): JsonResponse
    {
        Gate::authorize('review', $document);
        $validated = $this->decision($request);

        return response()->json(['data' => $this->verification->reject($document, $request->user(), $validated['reason'], $validated['note'] ?? null)->toPublicArray()]);
    }

    public function requestResubmission(Request $request, VerificationDocument $document): JsonResponse
    {
        Gate::authorize('review', $document);
        $validated = $this->decision($request);

        return response()->json(['data' => $this->verification->requestResubmission($document, $request->user(), $validated['reason'], $validated['note'] ?? null)->toPublicArray()]);
    }

    /** @return array{reason: string, note?: string|null} */
    private function decision(Request $request): array
    {
        return $request->validate([
            'reason' => ['required', Rule::in(array_keys((array) config('ehail_safety.verification.reject_reasons')))],
            'note' => ['nullable', 'string', 'max:1000', 'required_if:reason,other'],
        ]);
    }
}
