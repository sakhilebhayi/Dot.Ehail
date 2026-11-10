<?php

namespace App\Http\Controllers\Driver;

use App\Domain\Verification\VerificationService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Driver\Support\DriverState;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Documents checklist and upload. The upload is the safety domain's VerificationService::submit (file sniffing,
 * re-encoding, private storage, supersede rules); this controller only makes it work as a plain form too, so a
 * phone without script can still send a document.
 */
class DocumentsController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $state = DriverState::for($request->user());

        if ($state->profile() === null) {
            return redirect()->route('drive.apply');
        }

        return view('driver.documents', [
            'state' => $state,
            'documents' => $state->documents(),
            'limits' => [
                'image_kb' => (int) config('ehail_safety.uploads.max_image_kb'),
                'pdf_kb' => (int) config('ehail_safety.uploads.max_pdf_kb'),
            ],
            'focus' => $request->query('type'),
        ]);
    }

    public function store(Request $request, VerificationService $verification): JsonResponse|RedirectResponse
    {
        $state = DriverState::for($request->user());
        abort_if($state->profile() === null, 404);

        $validated = $request->validate([
            'type' => ['required', 'string', 'max:40'],
            // Two pickers on the form: the camera (document_photo) and a file; whichever was used.
            'document' => ['required_without:document_photo', 'nullable', 'file'],
            'document_photo' => ['required_without:document', 'nullable', 'file'],
            'vehicle_id' => ['nullable', 'integer'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // Only the driver's own vehicles; the service checks again.
        $vehicle = isset($validated['vehicle_id']) ? Vehicle::query()->where('driver_profile_id', $state->profile()->id)->find($validated['vehicle_id']) : null;

        $document = $verification->submit($request->user(), $validated['type'], $request->file('document') ?? $request->file('document_photo'), $vehicle, $validated['expires_on'] ?? null);

        if ($request->expectsJson()) {
            return response()->json(['data' => $document->toPublicArray(), 'message' => 'Sent. A person at Dot.Ehail reviews it; you will see the result here.'], 201);
        }

        return redirect()->route('drive.documents')->with('status', 'Sent. A person at Dot.Ehail reviews it; you will see the result here.');
    }
}
