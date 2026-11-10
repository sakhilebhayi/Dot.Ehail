<?php

namespace App\Http\Controllers\Ehail;

use App\Domain\Verification\Files\SecureFileStore;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\DriverApplicationSubmittedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DriverApplicationController extends Controller
{
    public function create(): View
    {
        // Only operator-approved fleets can be joined; a freshly created
        // fleet is pending until a platform operator vets it.
        $fleets = Fleet::approved()->orderBy('name')->get();

        return view('drivers.apply', ['fleets' => $fleets]);
    }

    public function store(Request $request): RedirectResponse
    {
        $existingProfile = DriverProfile::withoutGlobalScope('user')
            ->where('user_id', $request->user()->id)
            ->exists();

        if ($existingProfile) {
            return back()->withErrors(['license_number' => 'You already have a driver application on file.']);
        }

        // Driver app (R-46): "drive independently" registers a one-driver fleet in the applicant's name (pending
        // operator review, exactly like a new fleet); "join a fleet" ignores any own-fleet name left in the form.
        if ($request->input('mode') === 'independent') {
            $request->merge([
                'existing_fleet_id' => null,
                'new_fleet_name' => trim((string) $request->input('new_fleet_name')) !== '' ? $request->input('new_fleet_name') : $request->user()->name.' (independent)',
            ]);
        } elseif ($request->input('mode') === 'fleet') {
            $request->merge(['new_fleet_name' => null]);
        }

        $validator = Validator::make($request->all(), [
            // Unique through the blind index: the stored values are encrypted,
            // so a plain `unique:` rule could never match.
            'license_number' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail) {
                if (DriverProfile::licenseNumberTaken((string) $value)) {
                    $fail('The license number has already been taken.');
                }
            }],
            'id_number' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail) {
                if (DriverProfile::idNumberTaken((string) $value)) {
                    $fail('The id number has already been taken.');
                }
            }],
            'existing_fleet_id' => [
                'nullable',
                'required_without:new_fleet_name',
                Rule::exists('fleets', 'id')->where('status', Fleet::STATUS_APPROVED),
            ],
            'new_fleet_name' => 'nullable|string|required_without:existing_fleet_id',
            'vehicle_make' => 'required|string',
            'vehicle_model' => 'required|string',
            'vehicle_year' => 'required|integer',
            'vehicle_color' => 'required|string',
            'vehicle_plate_number' => 'required|string|unique:vehicles,plate_number',
            'vehicle_type' => 'required|string|in:economy,standard,premium,suv',
            // All three optional -- a driver can also upload/replace these
            // later via DriverDocumentController, so the application
            // itself doesn't hard-require them (existing applicants with
            // no documents on file should still be reviewable, not
            // silently blocked by a new required field).
            'license_document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'id_document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'vehicle_inspection_document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $validator->after(function ($validator) use ($request) {
            if (! empty($request->input('existing_fleet_id')) && ! empty($request->input('new_fleet_name'))) {
                $validator->errors()->add('new_fleet_name', 'Choose either an existing fleet or a new fleet name, not both.');
            }
        });

        $validated = $validator->validate();

        if (! empty($validated['new_fleet_name'])) {
            $fleet = Fleet::create([
                'name' => $validated['new_fleet_name'],
                'owner_user_id' => $request->user()->id,
                // The applicant owning the fleet must never be what
                // approves them: the fleet starts pending operator review.
                'status' => Fleet::STATUS_PENDING,
            ]);
        } else {
            $fleet = Fleet::approved()->findOrFail($validated['existing_fleet_id']);
        }

        $profile = DriverProfile::create([
            'user_id' => $request->user()->id,
            'fleet_id' => $fleet->id,
            'license_number' => $validated['license_number'],
            'id_number' => $validated['id_number'],
            'status' => 'pending',
        ]);

        Vehicle::create([
            'driver_profile_id' => $profile->id,
            'make' => $validated['vehicle_make'],
            'model' => $validated['vehicle_model'],
            'year' => $validated['vehicle_year'],
            'color' => $validated['vehicle_color'],
            'plate_number' => $validated['vehicle_plate_number'],
            'type' => $validated['vehicle_type'],
        ]);

        foreach (['license_document' => 'license', 'id_document' => 'id', 'vehicle_inspection_document' => 'vehicle_inspection'] as $field => $type) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $file = $request->file($field);
            $profile->documents()->create([
                'type' => $type,
                // EXIF/GPS stripped and re-encoded (R-46 security S-24).
                'file_path' => app(SecureFileStore::class)->store($file, 'driver-documents', 'legacy', ['image', 'pdf'], $field)->path,
                'original_filename' => $file->getClientOriginalName(),
            ]);
        }

        // An unvetted fleet's owner is the applicant themselves, so the
        // reviewers are the platform operators; otherwise the fleet owner.
        $reviewers = $fleet->isApproved()
            ? collect([$fleet->owner])
            : User::where('is_platform_operator', true)->get();

        foreach ($reviewers->filter()->reject(fn (User $reviewer) => $reviewer->id === $request->user()->id) as $reviewer) {
            $reviewer->notify(new DriverApplicationSubmittedNotification($profile));
        }

        // Next step in the driver app: the documents checklist.
        return redirect()->route('drive.onboarding')
            ->with('status', $fleet->isApproved()
                ? 'Application sent. The fleet owner will review it. Now upload your documents.'
                : 'Application sent. Dot.Ehail operations will review it. Now upload your documents.');
    }
}
