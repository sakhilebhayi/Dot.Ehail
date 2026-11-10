<?php

namespace App\Domain\Support\Http;

use App\Domain\Support\Models\SupportCase;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Support\SupportCaseService;
use App\Domain\Support\SupportCategory;
use App\Domain\Support\SupportPriority;
use App\Domain\Support\SupportStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Operations support queue (platform operators; route group carries the `operator` middleware). */
class OpsSupportController extends Controller
{
    public function __construct(private readonly SupportCaseService $cases) {}

    public function queue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['open', ...array_column(SupportStatus::cases(), 'value')])],
            'category' => ['nullable', Rule::enum(SupportCategory::class)],
            'assigned' => ['nullable', Rule::in(['me', 'unassigned', 'any'])],
        ]);

        $status = $validated['status'] ?? 'open';

        $cases = SupportCase::query()
            ->when($status === 'open', fn ($q) => $q->whereNotIn('status', ['resolved', 'closed']), fn ($q) => $q->where('status', $status))
            ->when(isset($validated['category']), fn ($q) => $q->where('category', $validated['category']))
            ->when(($validated['assigned'] ?? 'any') === 'me', fn ($q) => $q->where('owner_id', $request->user()->id))
            ->when(($validated['assigned'] ?? 'any') === 'unassigned', fn ($q) => $q->whereNull('owner_id'))
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('first_response_due_at')
            ->paginate(50);

        return response()->json([
            'data' => collect($cases->items())->map(fn (SupportCase $c) => $this->opsArray($c))->values(),
            'meta' => ['total' => $cases->total(), 'page' => $cases->currentPage(), 'last_page' => $cases->lastPage()],
            'resolution_codes' => SupportCaseService::RESOLUTION_CODES,
        ]);
    }

    public function show(SupportCase $case): JsonResponse
    {
        return response()->json([
            'data' => $this->opsArray($case) + ['meta' => $case->meta],
            'messages' => $case->messages->map(fn (SupportMessage $m) => [
                'id' => $m->id, 'author_id' => $m->author_id, 'author_role' => $m->author_role, 'visibility' => $m->visibility,
                'body' => $m->body, 'attachment' => $m->attachment_path ? ['name' => $m->attachment_name, 'mime' => $m->attachment_mime, 'url' => route('support.attachments.show', $m)] : null,
                'at' => $m->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function reply(Request $request, SupportCase $case): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required_without:attachment', 'nullable', 'string', 'max:5000'],
            'visibility' => ['nullable', Rule::in(['participants', 'requester', 'internal'])],
            'await_user' => ['nullable', 'boolean'],
            'attachment' => ['nullable', 'file'],
        ]);

        $message = $this->cases->reply($case, $request->user(), (string) ($validated['body'] ?? ''), $request->file('attachment'), $validated['visibility'] ?? 'requester', (bool) ($validated['await_user'] ?? false));

        return response()->json(['data' => ['id' => $message->id, 'visibility' => $message->visibility], 'case' => $this->opsArray($case->fresh())], 201);
    }

    public function transition(Request $request, SupportCase $case): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(SupportStatus::class)],
            'resolution_code' => ['nullable', Rule::in(array_keys(SupportCaseService::RESOLUTION_CODES)), 'required_if:status,resolved'],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->cases->transition($case, $request->user(), SupportStatus::from($validated['status']), $validated['resolution_code'] ?? null, $validated['resolution_note'] ?? null);

        return response()->json(['data' => $this->opsArray($updated)]);
    }

    public function assign(Request $request, SupportCase $case): JsonResponse
    {
        $validated = $request->validate(['owner_id' => ['nullable', 'integer', 'exists:users,id']]);
        $owner = isset($validated['owner_id']) ? User::findOrFail($validated['owner_id']) : $request->user();

        return response()->json(['data' => $this->opsArray($this->cases->assign($case, $owner, $request->user()))]);
    }

    public function priority(Request $request, SupportCase $case): JsonResponse
    {
        $validated = $request->validate(['priority' => ['required', Rule::enum(SupportPriority::class)]]);

        return response()->json(['data' => $this->opsArray($this->cases->setPriority($case, SupportPriority::from($validated['priority']), $request->user()))]);
    }

    public function escalate(Request $request, SupportCase $case): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return response()->json(['data' => $this->opsArray($this->cases->escalate($case, $request->user(), $validated['reason']))]);
    }

    /** @return array<string, mixed> */
    private function opsArray(SupportCase $case): array
    {
        return [
            'id' => $case->id,
            'reference' => $case->reference,
            'category' => $case->category->value,
            'status' => $case->status->value,
            'priority' => $case->priority->value,
            'subject' => $case->subject,
            'requester_id' => $case->user_id,
            'requester_role' => $case->requester_role,
            'counterparty_user_id' => $case->counterparty_user_id,
            'owner_id' => $case->owner_id,
            'ride_id' => $case->ride_id,
            'ride_payment_id' => $case->ride_payment_id,
            'safety_incident_id' => $case->safety_incident_id,
            'escalation_level' => $case->escalation_level,
            'first_response_due_at' => $case->first_response_due_at?->toIso8601String(),
            'resolution_due_at' => $case->resolution_due_at?->toIso8601String(),
            'first_responded_at' => $case->first_responded_at?->toIso8601String(),
            'sla_breached' => $case->sla_breached_at !== null,
            'resolution_code' => $case->resolution_code,
            'opened_at' => $case->created_at->toIso8601String(),
        ];
    }
}
