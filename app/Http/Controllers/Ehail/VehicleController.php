<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Vehicle;
use App\Services\Ehail\VehicleManager;
use App\Services\Ehail\VehicleRuleViolation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VehicleController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $profile = $this->profileOf($request);

        if ($profile === null) {
            return redirect()->route('drive.apply');
        }

        return view('drivers.vehicles', [
            'profile' => $profile,
            'vehicles' => $profile->vehicles()->with('documents')->orderByDesc('is_active')->orderBy('id')->get(),
            'canManage' => $request->user()->can('create', Vehicle::class),
        ]);
    }

    public function store(Request $request, VehicleManager $vehicles): RedirectResponse
    {
        $profile = $this->profileOf($request);

        if ($profile === null) {
            return redirect()->route('drive.apply');
        }

        Gate::authorize('create', Vehicle::class);

        $validated = $request->validate($this->rules($profile));

        try {
            $vehicles->add($profile, $this->attributes($validated), $request->boolean('activate'));
        } catch (VehicleRuleViolation $e) {
            return back()->withInput()->withErrors(['vehicle' => $e->getMessage()]);
        }

        return redirect()->route('drivers.vehicles.index')->with('status', 'Vehicle added.');
    }

    public function edit(Vehicle $vehicle): View
    {
        Gate::authorize('update', $vehicle);

        return view('drivers.vehicle-edit', ['vehicle' => $vehicle]);
    }

    public function update(Vehicle $vehicle, Request $request, VehicleManager $vehicles): RedirectResponse
    {
        Gate::authorize('update', $vehicle);

        $profile = $vehicle->driverProfile()->withoutGlobalScope('user')->firstOrFail();
        $validated = $request->validate($this->rules($profile, $vehicle));

        try {
            $vehicles->update($vehicle, $this->attributes($validated));
        } catch (VehicleRuleViolation $e) {
            return back()->withInput()->withErrors(['vehicle' => $e->getMessage()]);
        }

        return redirect()->route('drivers.vehicles.index')->with('status', 'Vehicle updated.');
    }

    public function activate(Vehicle $vehicle, VehicleManager $vehicles): RedirectResponse
    {
        Gate::authorize('activate', $vehicle);

        try {
            $vehicles->activate($vehicle);
        } catch (VehicleRuleViolation $e) {
            return back()->withErrors(['vehicle' => $e->getMessage()]);
        }

        return redirect()->route('drivers.vehicles.index')->with('status', 'Active vehicle changed.');
    }

    public function deactivate(Vehicle $vehicle, VehicleManager $vehicles): RedirectResponse
    {
        Gate::authorize('deactivate', $vehicle);

        try {
            $vehicles->deactivate($vehicle);
        } catch (VehicleRuleViolation $e) {
            return back()->withErrors(['vehicle' => $e->getMessage()]);
        }

        return redirect()->route('drivers.vehicles.index')->with('status', 'Vehicle deactivated. You are offline until you activate a vehicle.');
    }

    private function profileOf(Request $request): ?DriverProfile
    {
        return DriverProfile::withoutGlobalScope('user')->where('user_id', $request->user()->id)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(DriverProfile $profile, ?Vehicle $ignore = null): array
    {
        return [
            'make' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year' => ['required', 'integer', 'between:1980,'.((int) date('Y') + 1)],
            'color' => ['required', 'string', 'max:50'],
            'seats' => ['required', 'integer', 'between:1,8'],
            'type' => ['required', 'string', Rule::in(array_keys(config('ehail.tariff.multipliers')))],
            'plate_number' => ['required', 'string', 'max:20', $this->plateIsFree($profile, $ignore)],
            'activate' => ['nullable', 'boolean'],
        ];
    }

    /**
     * A plate is one physical car, so it can exist once on the platform. The
     * message says whether the clash is inside the driver's own fleet.
     */
    private function plateIsFree(DriverProfile $profile, ?Vehicle $ignore): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($profile, $ignore) {
            $existing = Vehicle::whereRaw('upper(plate_number) = ?', [Vehicle::normalizePlate((string) $value)])
                ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
                ->first();

            if ($existing === null) {
                return;
            }

            $sameFleet = DriverProfile::withoutGlobalScope('user')
                ->whereKey($existing->driver_profile_id)
                ->where('fleet_id', $profile->fleet_id)
                ->exists();

            $fail($sameFleet
                ? 'That plate is already registered in your fleet.'
                : 'That plate is already registered on Dot.Ehail.');
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        return collect($validated)->only(['make', 'model', 'year', 'color', 'seats', 'type', 'plate_number'])->all();
    }
}
