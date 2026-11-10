<?php

namespace App\Http\Controllers\Ehail;

use App\Domain\Verification\Files\PrivateFileResponse;
use App\Domain\Verification\Files\SecureFileStore;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Roadmap item (wiki.md §8): driver document/inspection workflow, beyond
 * the bare license_number/id_number strings DriverApplicationController
 * already collects. Kept separate from that controller's own upload
 * handling (DriverApplicationController::store() also accepts these same
 * three optional fields at application time) so a driver can add or
 * replace a document later too -- a license renewal shouldn't require a
 * whole new application.
 */
class DriverDocumentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $profile = DriverProfile::where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validate([
            'type' => ['required', Rule::in(['license', 'id', 'vehicle_inspection'])],
            // Optional: attach an inspection record to one of the driver's own
            // vehicles. Same upload, same disk -- only the link is new.
            'vehicle_id' => [
                'nullable',
                'prohibited_unless:type,vehicle_inspection',
                Rule::exists('vehicles', 'id')->where('driver_profile_id', $profile->id),
            ],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        // Sniffed, re-encoded (EXIF and GPS stripped) and randomly named, like every R-46 identity upload (S-24).
        $path = app(SecureFileStore::class)->store($request->file('document'), 'driver-documents', 'legacy', ['image', 'pdf'], 'document')->path;

        $profile->documents()->create([
            'type' => $validated['type'],
            'vehicle_id' => $validated['vehicle_id'] ?? null,
            'file_path' => $path,
            'original_filename' => $request->file('document')->getClientOriginalName(),
        ]);

        return redirect()->route('drivers.show', $profile)->with('status', 'Document uploaded.');
    }

    public function show(DriverDocument $document): Response
    {
        Gate::authorize('view', $document);

        $viewer = request()->user();
        $ownerId = $document->driverProfile()->withoutGlobalScope('user')->value('user_id');

        // Anyone but the driver opening an identity document is recorded, as on /verification (S-24).
        if ($viewer->id !== $ownerId) {
            AuditLog::record('driver_document.viewed', $viewer, $document, ['type' => $document->type, 'as' => $viewer->isPlatformOperator() ? 'operator' : 'fleet_owner']);
        }

        $mime = (string) (Storage::disk('driver-documents')->mimeType($document->file_path) ?: 'application/octet-stream');

        return PrivateFileResponse::make('driver-documents', $document->file_path, (string) $document->original_filename, $mime);
    }

    public function destroy(DriverDocument $document): RedirectResponse
    {
        Gate::authorize('delete', $document);

        $profile = $document->driverProfile()->withoutGlobalScope('user')->firstOrFail();

        Storage::disk('driver-documents')->delete($document->file_path);
        $document->delete();

        return redirect()->route('drivers.show', $profile)->with('status', 'Document deleted.');
    }
}
