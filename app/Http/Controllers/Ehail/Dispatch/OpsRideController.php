<?php

namespace App\Http\Controllers\Ehail\Dispatch;

use App\Domain\Dispatch\RideOperations;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Operations API (behind the `operator` middleware) for the wave-2 command
 * centre: live rides, a ride's timeline, force-cancel, reassign, flag for
 * review and resolve. Every action requires a written reason and is audited.
 */
class OpsRideController extends Controller
{
    use DispatchJson;

    public function index(Request $request, RideOperations $ops): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_map(fn (RideStatus $s) => $s->value, RideStatus::cases()))],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);

        $page = $ops->live($validated['status'] ?? null, (int) ($validated['per_page'] ?? 50));
        $now = now();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Ride $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'status_label' => $r->statusEnum()->label(),
                'created_at' => $r->created_at?->toIso8601String(),
                'scheduled_for' => $r->scheduled_for?->toIso8601String(),
                'searching_for_s' => $r->statusEnum()->isAwaitingDriver() && $r->search_started_at ? (int) $r->search_started_at->diffInSeconds($now, true) : null,
                'offers_sent' => $r->offers_sent,
                'reassignment_count' => $r->reassignment_count,
                'dispatch_priority' => $r->dispatch_priority,
                'passenger' => ['id' => $r->passenger_id, 'name' => $r->passenger?->name],
                'driver' => $r->driver_id ? ['id' => $r->driver_id, 'name' => $r->driver?->name] : null,
                'pickup_address' => $r->pickup_address,
                'dropoff_address' => $r->dropoff_address,
                'needs_review_reason' => $r->needs_review_reason,
            ])->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
        ]);
    }

    public function timeline(Ride $ride): JsonResponse
    {
        return response()->json([
            'ride_id' => $ride->id,
            'events' => $ride->events()->get()->map(fn ($e) => [
                'sequence' => $e->sequence, 'type' => $e->type, 'from' => $e->from_status, 'to' => $e->to_status,
                'actor_type' => $e->actor_type, 'actor_id' => $e->actor_id, 'reason_code' => $e->reason_code,
                'context' => $e->context, 'occurred_at' => $e->occurred_at->toIso8601String(),
            ]),
            'offers' => $ride->offers()->orderBy('id')->get()->map(fn ($o) => [
                'id' => $o->id, 'driver_id' => $o->driver_id, 'status' => $o->status, 'round' => $o->round,
                'radius_m' => $o->radius_m, 'pickup_eta_s' => $o->pickup_eta_s, 'eta_method' => $o->eta_method,
                'score' => $o->score, 'score_terms' => $o->score_terms, 'sent_at' => $o->sent_at?->toIso8601String(),
                'seen_at' => $o->seen_at?->toIso8601String(), 'responded_at' => $o->responded_at?->toIso8601String(),
                'reason' => $o->reason,
            ]),
        ]);
    }

    public function forceCancel(Request $request, Ride $ride, RideOperations $ops): JsonResponse
    {
        $reason = $this->reason($request);

        return $this->attempt(fn () => response()->json(['status' => $ops->forceCancel($ride, $request->user(), $reason)->status]));
    }

    public function reassign(Request $request, Ride $ride, RideOperations $ops): JsonResponse
    {
        $reason = $this->reason($request);
        $validated = $request->validate(['driver_id' => ['nullable', 'integer', 'exists:users,id']]);
        $driver = isset($validated['driver_id']) ? User::find($validated['driver_id']) : null;

        return $this->attempt(fn () => response()->json(['status' => $ops->reassign($ride, $request->user(), $reason, $driver)->status]));
    }

    public function needsReview(Request $request, Ride $ride, RideOperations $ops): JsonResponse
    {
        $reason = $this->reason($request);

        return $this->attempt(fn () => response()->json(['status' => $ops->markNeedsReview($ride, $request->user(), $reason)->status]));
    }

    public function resolve(Request $request, Ride $ride, RideOperations $ops): JsonResponse
    {
        $reason = $this->reason($request);
        $validated = $request->validate([
            'outcome' => ['required', Rule::in(['complete', 'cancel', 'redispatch'])],
            'final_fare_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        return $this->attempt(fn () => response()->json([
            'status' => $ops->resolve($ride, $request->user(), $validated['outcome'], $reason, $validated['final_fare_minor'] ?? null)->status,
        ]));
    }

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];
    }
}
