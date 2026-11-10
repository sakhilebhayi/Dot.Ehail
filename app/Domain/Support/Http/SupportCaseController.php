<?php

namespace App\Domain\Support\Http;

use App\Domain\Support\Models\SupportCase;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Support\SupportCaseService;
use App\Domain\Support\SupportCategory;
use App\Domain\Verification\Files\PrivateFileResponse;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Support cases for riders and drivers (and the counterparty of a lost-property case): open,
 * list, read the thread they may see, reply, download an attachment they may see.
 */
class SupportCaseController extends Controller
{
    public function __construct(private readonly SupportCaseService $cases) {}

    public function categories(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (SupportCategory $c) => [
            'value' => $c->value, 'label' => $c->label(), 'needs_ride' => $c->needsRide(), 'priority' => $c->defaultPriority()->value,
        ], SupportCategory::cases())]);
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $cases = SupportCase::query()
            ->where(fn ($q) => $q->where('user_id', $userId)->orWhere('counterparty_user_id', $userId))
            ->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $cases->map(fn (SupportCase $c) => $this->summary($c, $request))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::enum(SupportCategory::class)],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'ride_id' => ['nullable', 'integer'],
            'ride_payment_id' => ['nullable', 'integer'],
            'safety_incident_id' => ['nullable', 'integer'],
            'requested_amount_minor' => ['nullable', 'integer', 'min:0'],
            'attachment' => ['nullable', 'file'],
        ]);

        $case = $this->cases->open(
            $request->user(),
            SupportCategory::from($validated['category']),
            $validated['subject'],
            $validated['body'],
            array_intersect_key($validated, array_flip(['ride_id', 'ride_payment_id', 'safety_incident_id', 'requested_amount_minor'])),
            $request->file('attachment'),
        );

        return response()->json(['data' => $this->summary($case, $request)], 201);
    }

    public function show(Request $request, SupportCase $case): JsonResponse
    {
        $role = $this->cases->roleOf($case, $request->user());
        abort_if($role === null || $role === 'agent', 404);

        return response()->json([
            'data' => $this->summary($case, $request),
            'messages' => $this->thread($case, $request),
        ]);
    }

    public function reply(Request $request, SupportCase $case): JsonResponse
    {
        $role = $this->cases->roleOf($case, $request->user());
        abort_if($role === null || $role === 'agent', 404);

        $validated = $request->validate([
            'body' => ['required_without:attachment', 'nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file'],
        ]);

        $message = $this->cases->reply($case, $request->user(), (string) ($validated['body'] ?? ''), $request->file('attachment'));

        return response()->json(['data' => $this->messageArray($message, $case, $request)], 201);
    }

    public function attachment(Request $request, SupportMessage $message): Response
    {
        $case = $message->supportCase;
        abort_if($case === null || $message->attachment_path === null, 404);
        abort_unless(in_array($message->visibility, $this->cases->visibleTo($case, $request->user()), true), 404);

        if ($request->user()->id !== $message->author_id) {
            AuditLog::record('support_attachment.viewed', $request->user(), $case, ['message_id' => $message->id]);
        }

        return PrivateFileResponse::make($message->attachment_disk, $message->attachment_path, (string) $message->attachment_name, (string) $message->attachment_mime);
    }

    /** @return array<string, mixed> */
    private function summary(SupportCase $case, Request $request): array
    {
        $role = $this->cases->roleOf($case, $request->user());

        return [
            'id' => $case->id,
            'reference' => $case->reference,
            'category' => $case->category->value,
            'category_label' => $case->category->label(),
            'status' => $case->status->value,
            'priority' => $case->priority->value,
            'subject' => $role === 'counterparty' ? 'Item left after a trip' : $case->subject,
            'ride_id' => $case->ride_id,
            'my_role' => $role,
            'resolution_code' => $case->resolution_code,
            'resolution' => $case->resolution_code ? SupportCaseService::RESOLUTION_CODES[$case->resolution_code] ?? null : null,
            'fare_review' => $role === 'requester' ? ($case->meta['fare_review']['status'] ?? null) : null,
            'opened_at' => $case->created_at->toIso8601String(),
            'updated_at' => $case->updated_at->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function thread(SupportCase $case, Request $request): array
    {
        return $case->messages()->whereIn('visibility', $this->cases->visibleTo($case, $request->user()))->get()
            ->map(fn (SupportMessage $m) => $this->messageArray($m, $case, $request))->values()->all();
    }

    /** @return array<string, mixed> */
    private function messageArray(SupportMessage $message, SupportCase $case, Request $request): array
    {
        $mine = $message->author_id === $request->user()->id;

        return [
            'id' => $message->id,
            // Names stay inside the platform: the parties see roles, not each other's details.
            'from' => $mine ? 'you' : match ($message->author_role) {
                'agent' => 'Dot.Ehail support',
                'system' => 'Dot.Ehail',
                'counterparty' => $case->requester_role === 'passenger' ? 'The driver' : 'The passenger',
                default => $case->requester_role === 'passenger' ? 'The passenger' : ($case->requester_role === 'driver' ? 'The driver' : 'The requester'),
            },
            'body' => $message->body,
            'attachment' => $message->attachment_path ? [
                'name' => $message->attachment_name,
                'mime' => $message->attachment_mime,
                'size' => $message->attachment_size,
                'url' => route('support.attachments.show', $message),
            ] : null,
            'at' => $message->created_at?->toIso8601String(),
        ];
    }
}
