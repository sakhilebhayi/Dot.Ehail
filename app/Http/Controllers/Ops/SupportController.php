<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Ops\Audit\OpsAudit;
use App\Domain\Payments\Models\Payment;
use App\Domain\Support\Models\SupportCase;
use App\Domain\Support\SupportCaseService;
use App\Domain\Support\SupportCategory;
use App\Domain\Support\SupportStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Support: the queue with SLA timers (first response and resolution, from the support domain's own due times),
 * one case with its whole thread including internal notes, its linked ride, payment and incident, and the actions
 * (reply, assign, priority, status with a resolution code, escalate) posted to the support API. Lost property is a
 * queue of its own. No canned replies: every answer is written.
 */
class SupportController extends Controller
{
    public const OPEN = ['new', 'triaged', 'in_progress', 'waiting_on_user'];

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'queue' => ['nullable', Rule::in(['open', 'mine', 'unassigned', 'lost_property', 'breached', 'closed'])],
            'category' => ['nullable', Rule::enum(SupportCategory::class)],
        ]);
        $queue = $validated['queue'] ?? 'open';
        $user = $request->user();

        $cases = SupportCase::query()
            ->with(['requester:id,name', 'owner:id,name'])
            ->when($queue === 'closed', fn ($q) => $q->whereIn('status', ['resolved', 'closed']), fn ($q) => $q->whereIn('status', self::OPEN))
            ->when($queue === 'mine', fn ($q) => $q->where('owner_id', $user->id))
            ->when($queue === 'unassigned', fn ($q) => $q->whereNull('owner_id'))
            ->when($queue === 'lost_property', fn ($q) => $q->where('category', SupportCategory::LostProperty->value))
            ->when($queue === 'breached', fn ($q) => $q->whereNotNull('sla_breached_at'))
            ->when($validated['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderByRaw('coalesce(first_response_due_at, resolution_due_at, created_at)')
            ->paginate(50)
            ->withQueryString();

        $open = SupportCase::query()->whereIn('status', self::OPEN);

        return view('ops.support.index', [
            'cases' => $cases,
            'queue' => $queue,
            'category' => $validated['category'] ?? null,
            'counts' => [
                'open' => (clone $open)->count(),
                'unassigned' => (clone $open)->whereNull('owner_id')->count(),
                'mine' => (clone $open)->where('owner_id', $user->id)->count(),
                'breached' => (clone $open)->whereNotNull('sla_breached_at')->count(),
                'awaiting_first' => (clone $open)->whereNull('first_responded_at')->count(),
                'lost_property' => (clone $open)->where('category', SupportCategory::LostProperty->value)->count(),
            ],
        ]);
    }

    public function show(Request $request, SupportCase $case, OpsAccess $access, OpsAudit $audit): View
    {
        $case->load(['messages.author:id,name', 'requester:id,name,email', 'owner:id,name', 'ride', 'incident']);
        $audit->viewed('support_case', $request->user(), $case);

        return view('ops.support.show', [
            'case' => $case,
            'canAct' => $access->can($request->user(), OpsPermission::SUPPORT_ACT),
            'statuses' => collect(SupportStatus::cases())->filter(fn (SupportStatus $s) => $case->status->canBecome($s))->values(),
            'resolutionCodes' => SupportCaseService::RESOLUTION_CODES,
            'operators' => User::query()->where('is_platform_operator', true)->orderBy('name')->get(['id', 'name']),
            'payment' => $case->ride_payment_id ? Payment::query()->find($case->ride_payment_id) : null,
            'counterparty' => $case->counterparty_user_id ? User::query()->find($case->counterparty_user_id, ['id', 'name']) : null,
        ]);
    }
}
