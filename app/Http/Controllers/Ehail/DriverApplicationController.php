<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Vehicle;
use App\Notifications\DriverApplicationSubmittedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class DriverApplicationController extends Controller
{
    public function create(): View
    {
        $fleets = Fleet::orderBy('name')->get();

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

        $validator = Validator::make($request->all(), [
            'license_number' => 'required|string|unique:driver_profiles,license_number',
            'id_number' => 'required|string|unique:driver_profiles,id_number',
            'existing_fleet_id' => 'nullable|required_without:new_fleet_name|exists:fleets,id',
            'new_fleet_name' => 'nullable|string|required_without:existing_fleet_id',
            'vehicle_make' => 'required|string',
            'vehicle_model' => 'required|string',
            'vehicle_year' => 'required|integer',
            'vehicle_color' => 'required|string',
            'vehicle_plate_number' => 'required|string|unique:vehicles,plate_number',
            'vehicle_type' => 'required|string|in:economy,standard,premium,suv',
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
            ]);
        } else {
            $fleet = Fleet::findOrFail($validated['existing_fleet_id']);
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

        $fleet->owner->notify(new DriverApplicationSubmittedNotification($profile));

        return redirect()->route('drivers.show', $profile)
            ->with('status', 'Application submitted. The fleet owner will review it.');
    }
}
