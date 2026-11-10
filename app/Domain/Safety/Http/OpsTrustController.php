<?php

namespace App\Domain\Safety\Http;

use App\Domain\Safety\Models\RatingAppeal;
use App\Domain\Safety\Models\UserReport;
use App\Domain\Safety\RatingModeration;
use App\Domain\Safety\UserReportService;
use App\Http\Controllers\Controller;
use App\Models\RideRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Operations queues for private user reports, held or reported rating comments, and appeals. */
class OpsTrustController extends Controller
{
    public function reports(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['nullable', Rule::in(['open', 'reviewing', 'actioned', 'dismissed'])]]);

        $reports = UserReport::query()->with(['reportedUser:id,name', 'reporter:id,name'])
            ->where('status', $validated['status'] ?? 'open')
            ->orderBy('id')->paginate(50);

        return response()->json([
            'data' => collect($reports->items())->map(fn (UserReport $r) => [
                'id' => $r->id,
                'category' => $r->category,
                'status' => $r->status,
                'reporter' => $r->reporter_id ? ['id' => $r->reporter_id, 'name' => $r->reporter?->name, 'role' => $r->reporter_role] : ['id' => null, 'name' => 'System', 'role' => 'system'],
                'reported_user' => ['id' => $r->reported_user_id, 'name' => $r->reportedUser?->name],
                'ride_id' => $r->ride_id,
                'description' => $r->description,
                'evidence' => $r->evidence,
                'open_reports_against_user' => UserReport::query()->where('reported_user_id', $r->reported_user_id)->whereIn('status', ['open', 'reviewing'])->count(),
                'at' => $r->created_at->toIso8601String(),
            ])->values(),
            'meta' => ['total' => $reports->total(), 'page' => $reports->currentPage()],
        ]);
    }

    public function reviewReport(Request $request, UserReport $report, UserReportService $service): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['reviewing', 'actioned', 'dismissed'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $service->review($report, $request->user(), $validated['status'], $validated['note'] ?? null)->only(['id', 'status'])]);
    }

    public function heldComments(): JsonResponse
    {
        $ratings = RideRating::query()->where('comment_status', 'held')->orderBy('id')->limit(100)->get();

        return response()->json(['data' => $ratings->map(fn (RideRating $r) => [
            'id' => $r->id, 'ride_id' => $r->ride_id, 'rated_user_id' => $r->rated_user_id, 'rated_role' => $r->rated_role,
            'rating' => $r->rating, 'comment' => $r->comment, 'reason' => $r->moderation_reason, 'at' => $r->created_at->toIso8601String(),
        ])->values()]);
    }

    public function moderateComment(Request $request, RideRating $rating, RatingModeration $moderation): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['hide', 'release'])],
            'reason' => ['nullable', 'string', 'max:40', 'required_if:action,hide'],
        ]);

        $updated = $validated['action'] === 'hide'
            ? $moderation->hide($rating, $request->user(), $validated['reason'])
            : $moderation->release($rating, $request->user());

        return response()->json(['data' => ['id' => $updated->id, 'comment_status' => $updated->comment_status]]);
    }

    public function appeals(): JsonResponse
    {
        $appeals = RatingAppeal::query()->with('rating')->where('status', 'pending')->orderBy('id')->limit(100)->get();

        return response()->json(['data' => $appeals->map(fn (RatingAppeal $a) => [
            'id' => $a->id, 'driver_user_id' => $a->user_id, 'reason' => $a->reason,
            'rating' => $a->rating ? ['id' => $a->rating->id, 'ride_id' => $a->rating->ride_id, 'rating' => $a->rating->rating, 'comment' => $a->rating->comment] : null,
            'at' => $a->created_at->toIso8601String(),
        ])->values()]);
    }

    public function decideAppeal(Request $request, RatingAppeal $appeal, RatingModeration $moderation): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['upheld', 'rejected'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $moderation->decideAppeal($appeal, $request->user(), $validated['decision'], $validated['note'] ?? null)->only(['id', 'status'])]);
    }
}
