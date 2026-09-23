<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $path = $request->file('document')->store('', 'driver-documents');

        $profile->documents()->create([
            'type' => $validated['type'],
            'file_path' => $path,
            'original_filename' => $request->file('document')->getClientOriginalName(),
        ]);

        return redirect()->route('drivers.show', $profile)->with('status', 'Document uploaded.');
    }

    public function show(DriverDocument $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        return Storage::disk('driver-documents')->response($document->file_path, $document->original_filename);
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
