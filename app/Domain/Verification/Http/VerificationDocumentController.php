<?php

namespace App\Domain\Verification\Http;

use App\Domain\Verification\DocumentRequirements;
use App\Domain\Verification\DriverEligibility;
use App\Domain\Verification\Files\PrivateFileResponse;
use App\Domain\Verification\Models\VerificationDocument;
use App\Domain\Verification\VerificationService;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Driver-side verification API (wave-2 driver app): requirements, status, upload, own documents,
 * go-online eligibility, and the file stream (shared with reviewers, policy-checked and logged).
 */
class VerificationDocumentController extends Controller
{
    public function __construct(
        private readonly VerificationService $verification,
        private readonly DocumentRequirements $requirements,
    ) {}

    public function requirements(): JsonResponse
    {
        $market = $this->requirements->defaultMarket();
        $types = [];

        foreach ($this->requirements->types($market) as $code => $type) {
            $types[] = [
                'type' => $code,
                'subject' => $type['subject'],
                'label' => $type['label'],
                'required' => (bool) $type['required'],
                'expiry_required' => (bool) $type['expiry_required'],
                'accepts' => $type['kinds'],
                'category_rules' => $type['categories'] ?? (object) [],
            ];
        }

        return response()->json([
            'market' => $market,
            'types' => $types,
            'limits' => [
                'max_image_kb' => (int) config('ehail_safety.uploads.max_image_kb'),
                'max_pdf_kb' => (int) config('ehail_safety.uploads.max_pdf_kb'),
                'virus_scanning' => false,
            ],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $profile = $this->ownProfile($request);
        $vehicles = Vehicle::query()->where('driver_profile_id', $profile->id)->orderBy('id')->get();
        $active = $vehicles->firstWhere('is_active', true);

        return response()->json([
            'driver_profile' => ['id' => $profile->id, 'status' => $profile->status],
            'driver' => $this->verification->driverStatus($profile),
            'vehicles' => $vehicles->map(fn (Vehicle $v) => [
                'id' => $v->id,
                'description' => trim("{$v->color} {$v->make} {$v->model}"),
                'category' => $v->type,
                'is_active' => (bool) $v->is_active,
                'verification' => $this->verification->vehicleStatus($v),
            ])->values(),
            'eligibility' => $active ? app(DriverEligibility::class)->canGoOnline($request->user(), $active)->toArray() : [
                'allowed' => false,
                'documents_enforced' => (bool) config('ehail_safety.verification.require_documents'),
                'reasons' => [['code' => 'no_active_vehicle', 'subject' => 'vehicle', 'type' => null, 'message' => 'Add a vehicle and make it active.']],
            ],
        ]);
    }

    public function eligibility(Request $request): JsonResponse
    {
        $profile = $this->ownProfile($request);
        $validated = $request->validate(['vehicle_id' => ['required', 'integer']]);
        $vehicle = Vehicle::query()->where('driver_profile_id', $profile->id)->findOrFail($validated['vehicle_id']);

        return response()->json(app(DriverEligibility::class)->canGoOnline($request->user(), $vehicle)->toArray());
    }

    public function index(Request $request): JsonResponse
    {
        $documents = VerificationDocument::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $documents->map->toPublicArray()->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:40'],
            'document' => ['required', 'file'],
            'vehicle_id' => ['nullable', 'integer'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $vehicle = isset($validated['vehicle_id']) ? Vehicle::query()->find($validated['vehicle_id']) : null;

        $document = $this->verification->submit(
            $request->user(),
            $validated['type'],
            $request->file('document'),
            $vehicle,
            $validated['expires_on'] ?? null,
        );

        return response()->json(['data' => $document->toPublicArray()], 201);
    }

    public function show(Request $request, VerificationDocument $document): JsonResponse
    {
        abort_unless(Gate::allows('view', $document), 404);

        $isOwner = $request->user()->id === $document->user_id;

        return response()->json([
            'data' => $document->toPublicArray(),
            // The driver sees what happened and why; who reviewed it is for operations only.
            'history' => $document->reviews->map(fn ($review) => [
                'action' => $review->action,
                'to_status' => $review->to_status,
                'reason' => $review->reason,
                'note' => $review->note,
                'at' => $review->created_at?->toIso8601String(),
                'actor_id' => $isOwner ? null : $review->actor_id,
            ])->values(),
        ]);
    }

    public function file(Request $request, VerificationDocument $document): Response
    {
        abort_unless(Gate::allows('view', $document), 404);

        $this->verification->recordView($document, $request->user());

        return PrivateFileResponse::make($document->disk, $document->file_path, $document->original_filename, $document->mime);
    }

    private function ownProfile(Request $request): DriverProfile
    {
        return DriverProfile::withoutGlobalScope('user')->where('user_id', $request->user()->id)->firstOrFail();
    }
}
