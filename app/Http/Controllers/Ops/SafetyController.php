<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Ops\Audit\OpsAudit;
use App\Domain\Safety\Http\OpsIncidentController;
use App\Domain\Safety\Models\SafetyCheckIn;
use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Safety\Models\UserReport;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Safety operations: the incidents console (severity, timers, ownership), one incident with its trip context and
 * the notification log ("what we did"), check-ins, flagged cancellation patterns, user reports, and the trail of
 * who viewed what. Actions post to the safety API (acknowledge, assign, escalate, note, resolve, close, review).
 */
class SafetyController extends Controller
{
    public const ACTIVE = ['open', 'acknowledged', 'escalated'];

    public function index(Request $request): View
    {
        $validated = $request->validate(['status' => ['nullable', Rule::in(['active', 'resolved', 'closed', 'all'])]]);
        $status = $validated['status'] ?? 'active';

        $incidents = SafetyIncident::query()
            ->with(['reporter:id,name', 'owner:id,name', 'ride:id,status,pickup_address'])
            ->when($status === 'active', fn ($q) => $q->whereIn('status', self::ACTIVE))
            ->when(in_array($status, ['resolved', 'closed'], true), fn ($q) => $q->where('status', $status))
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderBy('created_at')
            ->paginate(50)
            ->withQueryString();

        $active = SafetyIncident::query()->whereIn('status', self::ACTIVE);

        return view('ops.safety.index', [
            'incidents' => $incidents,
            'status' => $status,
            'counts' => [
                'active' => (clone $active)->count(),
                'unacknowledged' => (clone $active)->where('status', 'open')->count(),
                'critical' => (clone $active)->where('severity', 'critical')->count(),
                'overdue' => (clone $active)->where('status', 'open')->where('next_escalation_at', '<', now())->count(),
                'checkins' => SafetyCheckIn::query()->where('status', 'pending')->count(),
                'reports' => UserReport::query()->whereIn('status', ['open', 'reviewing'])->count(),
            ],
        ]);
    }

    public function show(Request $request, SafetyIncident $incident, OpsAccess $access, OpsAudit $audit): View
    {
        $incident->load(['events', 'notifications', 'reporter:id,name', 'owner:id,name', 'ride.passenger:id,name', 'ride.driver:id,name']);
        $audit->viewed('safety_incident', $request->user(), $incident);

        return view('ops.safety.show', [
            'incident' => $incident,
            'canAct' => $access->can($request->user(), OpsPermission::SAFETY_ACT),
            'resolutionCodes' => OpsIncidentController::RESOLUTION_CODES,
            'operators' => User::query()->where('is_platform_operator', true)->orderBy('name')->get(['id', 'name']),
            'viewers' => $access->can($request->user(), OpsPermission::AUDIT_VIEW)
                ? AuditLog::query()->with('actor:id,name')->where('subject_type', $incident->getMorphClass())->where('subject_id', $incident->id)->orderByDesc('id')->limit(50)->get()
                : null,
            'reports' => $incident->ride_id ? UserReport::query()->where('ride_id', $incident->ride_id)->get() : collect(),
            'checkIns' => $incident->ride_id ? SafetyCheckIn::query()->where('ride_id', $incident->ride_id)->orderBy('id')->get() : collect(),
        ]);
    }

    public function checkIns(Request $request): View
    {
        $validated = $request->validate(['status' => ['nullable', Rule::in(['pending', 'all'])]]);
        $status = $validated['status'] ?? 'pending';

        return view('ops.safety.check-ins', [
            'status' => $status,
            'checkIns' => SafetyCheckIn::query()->with('user:id,name')
                ->when($status === 'pending', fn ($q) => $q->where('status', 'pending'))
                ->orderByDesc('prompted_at')->paginate(50)->withQueryString(),
        ]);
    }

    public function patterns(Request $request, OpsAccess $access): View
    {
        return view('ops.safety.patterns', [
            'flags' => UserReport::query()->with('reportedUser:id,name')
                ->where('category', 'cancellation_pattern')->orderByRaw("case status when 'open' then 0 when 'reviewing' then 1 else 2 end")
                ->orderByDesc('id')->paginate(50),
            'canAct' => $access->can($request->user(), OpsPermission::SAFETY_ACT),
        ]);
    }

    public function reports(Request $request, OpsAccess $access): View
    {
        $validated = $request->validate(['status' => ['nullable', Rule::in(['open', 'reviewing', 'actioned', 'dismissed', 'all'])]]);
        $status = $validated['status'] ?? 'open';

        return view('ops.safety.reports', [
            'status' => $status,
            'reports' => UserReport::query()->with(['reporter:id,name', 'reportedUser:id,name'])
                ->where('category', '!=', 'cancellation_pattern')
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->orderByDesc('id')->paginate(50)->withQueryString(),
            'canAct' => $access->can($request->user(), OpsPermission::SAFETY_ACT),
        ]);
    }

    /** Who opened what: every view and file-open record in the audit log, newest first. */
    public function access(Request $request): View
    {
        $validated = $request->validate(['actor' => ['nullable', 'integer'], 'what' => ['nullable', Rule::in(['all', 'documents', 'incidents', 'drivers', 'rides', 'cases'])]]);
        $what = $validated['what'] ?? 'all';

        $patterns = match ($what) {
            'documents' => ['verification_document.viewed', 'ops.viewed.verification_document'],
            'incidents' => ['ops.viewed.safety_incident'],
            'drivers' => ['ops.viewed.driver', 'driver_profile.opened', 'safety.driver_photo_viewed'],
            'rides' => ['ops.viewed.ride'],
            'cases' => ['ops.viewed.support_case', 'support_attachment.viewed'],
            default => null,
        };

        $rows = AuditLog::query()->with('actor:id,name')
            ->where(function ($q) use ($patterns) {
                if ($patterns !== null) {
                    $q->whereIn('action', $patterns);

                    return;
                }
                $q->where('action', 'like', 'ops.viewed.%')
                    ->orWhereIn('action', ['verification_document.viewed', 'driver_profile.opened', 'safety.driver_photo_viewed', 'support_attachment.viewed']);
            })
            ->when($validated['actor'] ?? null, fn ($q, $actor) => $q->where('actor_user_id', $actor))
            ->orderByDesc('id')
            ->paginate(100)
            ->withQueryString();

        return view('ops.safety.access', ['rows' => $rows, 'what' => $what, 'actor' => $validated['actor'] ?? null]);
    }
}
