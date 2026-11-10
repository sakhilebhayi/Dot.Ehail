<?php

namespace App\Http\Controllers\Fleet;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Catalog\Services\CategoryEligibility;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Fleet\DocumentOverview;
use App\Domain\Fleet\FleetVehicles;
use App\Domain\Fleet\Models\FleetVehicleAssignment;
use App\Domain\Fleet\Models\FleetVehiclePermit;
use App\Domain\Fleet\Models\VehicleMaintenanceRecord;
use App\Domain\Fleet\VehicleAssignments;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Fleet vehicles: the register (fleet-held vehicles and drivers' own), adding a vehicle, one vehicle's page
 * (details, features the ride catalogue reads, the categories it qualifies for, document status, who may drive
 * it and who has it now, maintenance), and the assignment actions.
 */
class FleetVehicleController extends FleetController
{
    public function index(Request $request, FleetVehicles $vehicles): View
    {
        $fleet = $this->fleet($request, 'vehicles');
        $user = $request->user();
        $show = in_array($request->query('show'), ['fleet', 'drivers', 'retired'], true) ? $request->query('show') : 'all';

        $list = $this->access->vehicleQuery($user, $fleet)
            ->when($show === 'retired', fn ($q) => $q->whereNotNull('retired_at'), fn ($q) => $q->whereNull('retired_at'))
            ->when($show === 'fleet', fn ($q) => $q->where('owned_by', 'fleet'))
            ->when($show === 'drivers', fn ($q) => $q->where('owned_by', 'driver'))
            ->with('driverProfile.user:id,name')
            ->orderBy('plate_number')
            ->get();

        $working = DriverAvailability::query()->whereIn('vehicle_id', $list->pluck('id'))->where('state', '!=', DriverAvailability::OFFLINE)->pluck('state', 'vehicle_id');

        return view('fleet.vehicles.index', [
            'fleet' => $fleet,
            'fleets' => $this->access->fleetsFor($user),
            'vehicles' => $list,
            'show' => $show,
            'working' => $working,
            'nextService' => fn (Vehicle $v) => $vehicles->nextService($v, (int) config('ehail_fleet.alerts.service_due_days'), (int) config('ehail_fleet.alerts.service_due_km')),
            'canWrite' => $this->access->can($user, $fleet, 'vehicles', true),
        ]);
    }

    public function create(Request $request): View
    {
        $fleet = $this->fleet($request, 'vehicles', true);

        return view('fleet.vehicles.create', ['fleet' => $fleet, 'fleets' => $this->access->fleetsFor($request->user()), 'features' => FleetVehicles::FEATURES]);
    }

    public function store(Request $request, FleetVehicles $vehicles): RedirectResponse|JsonResponse
    {
        $fleet = $this->fleet($request, 'vehicles', true);
        $data = $request->validate(self::rules());

        return $this->attempt($request, function () use ($vehicles, $fleet, $data, $request) {
            $vehicle = $vehicles->register($fleet, $data + ['features' => $data['features'] ?? []], $request->user());

            return redirect()->route('fleet.vehicles.show', $vehicle);
        }, 'Vehicle added. Upload its documents and give it to a driver.');
    }

    public function show(Request $request, int $vehicle, FleetVehicles $vehicles, DocumentOverview $documents, CategoryEligibility $categories, VehicleAssignments $assignments): View
    {
        $fleet = $this->fleet($request, 'vehicles');
        $user = $request->user();
        $model = $this->access->vehicleQuery($user, $fleet)->with('driverProfile.user:id,name')->findOrFail($vehicle);

        $catalogue = RideCategory::query()->activeRides()->with('vehicleRules')->orderBy('display_order')->get()
            ->map(fn (RideCategory $c) => ['category' => $c, 'failures' => $categories->vehicleFailures($c, $model)]);

        $permits = FleetVehiclePermit::query()->active()->where('vehicle_id', $model->id)->with('driverProfile.user:id,name')->get();
        $permitted = $permits->pluck('driver_profile_id')->all();

        return view('fleet.vehicles.show', [
            'fleet' => $fleet,
            'fleets' => $this->access->fleetsFor($user),
            'vehicle' => $model,
            'documents' => $documents->vehicleStatus($model),
            'catalogue' => $catalogue,
            'permits' => $permits,
            'candidates' => $this->access->drivers($user, $fleet)->where('status', 'approved')->reject(fn (DriverProfile $p) => in_array($p->id, $permitted, true))->values(),
            'assignment' => $vehicles->currentAssignment($model),
            'history' => FleetVehicleAssignment::query()->where('vehicle_id', $model->id)->with('driverProfile.user:id,name')->latest('started_at')->limit(8)->get(),
            'inUse' => $assignments->inUse($model),
            'maintenance' => VehicleMaintenanceRecord::query()->where('vehicle_id', $model->id)->latest('performed_on')->latest('id')->limit(10)->get(),
            'nextService' => $vehicles->nextService($model, (int) config('ehail_fleet.alerts.service_due_days'), (int) config('ehail_fleet.alerts.service_due_km')),
            'features' => FleetVehicles::FEATURES,
            'kinds' => VehicleMaintenanceRecord::KINDS,
            'canWrite' => $this->access->can($user, $fleet, 'vehicles', true),
            'canMaintain' => $this->access->can($user, $fleet, 'maintenance', true),
        ]);
    }

    public function update(Request $request, int $vehicle, FleetVehicles $vehicles): RedirectResponse|JsonResponse
    {
        [$fleet, $model] = $this->vehicle($request, $vehicle);
        $data = $request->validate(self::rules($model));

        if ($model->owned_by === 'driver') {
            // A driver's own car: its details are the driver's to change; the fleet records features and doors only.
            $data = array_intersect_key($data, array_flip(['doors', 'features']));
        }

        return $this->attempt($request, fn () => $vehicles->update($fleet, $model, $data + ['features' => $data['features'] ?? []], $request->user()), 'Vehicle saved.');
    }

    public function retire(Request $request, int $vehicle, FleetVehicles $vehicles): RedirectResponse|JsonResponse
    {
        [$fleet, $model] = $this->vehicle($request, $vehicle);

        return $this->attempt($request, fn () => $vehicles->retire($fleet, $model, $request->user()), 'Vehicle retired. It no longer appears in the register.');
    }

    public function reinstate(Request $request, int $vehicle, FleetVehicles $vehicles): RedirectResponse|JsonResponse
    {
        [$fleet, $model] = $this->vehicle($request, $vehicle);

        return $this->attempt($request, fn () => $vehicles->reinstate($fleet, $model, $request->user()), 'Vehicle back in the register.');
    }

    public function grantPermit(Request $request, int $vehicle, VehicleAssignments $assignments): RedirectResponse|JsonResponse
    {
        [$fleet, $model] = $this->vehicle($request, $vehicle);
        $profile = $this->fleetDriver($request, $fleet);

        return $this->attempt($request, fn () => $assignments->grantPermit($fleet, $model, $profile, $request->user()), ($profile->user?->name ?? 'The driver').' may now drive '.$model->plate_number.'.');
    }

    public function revokePermit(Request $request, int $vehicle, int $profile, VehicleAssignments $assignments): RedirectResponse|JsonResponse
    {
        [$fleet, $model] = $this->vehicle($request, $vehicle);
        $driver = DriverProfile::withoutGlobalScope('user')->where('fleet_id', $fleet->id)->findOrFail($profile);

        return $this->attempt($request, fn () => $assignments->revokePermit($fleet, $model, $driver, $request->user()), 'Permission withdrawn.');
    }

    public function assign(Request $request, int $vehicle, VehicleAssignments $assignments): RedirectResponse|JsonResponse
    {
        [$fleet, $model] = $this->vehicle($request, $vehicle);
        $profile = $this->fleetDriver($request, $fleet);

        return $this->attempt($request, fn () => $assignments->assign($fleet, $model, $profile, $request->user()), $model->plate_number.' is now with '.($profile->user?->name ?? 'the driver').'.');
    }

    public function unassign(Request $request, int $vehicle, VehicleAssignments $assignments): RedirectResponse|JsonResponse
    {
        [$fleet, $model] = $this->vehicle($request, $vehicle);

        return $this->attempt($request, fn () => $assignments->unassign($fleet, $model, $request->user()), $model->plate_number.' is back with the fleet.');
    }

    /**
     * @return array{0: Fleet, 1: Vehicle}
     */
    private function vehicle(Request $request, int $vehicle): array
    {
        $fleet = $this->fleet($request, 'vehicles', true);

        return [$fleet, Vehicle::query()->where('fleet_id', $fleet->id)->findOrFail($vehicle)];
    }

    private function fleetDriver(Request $request, Fleet $fleet): DriverProfile
    {
        $id = (int) $request->validate(['driver_profile_id' => ['required', 'integer']])['driver_profile_id'];

        return DriverProfile::withoutGlobalScope('user')->where('fleet_id', $fleet->id)->with('user:id,name')->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(?Vehicle $vehicle = null): array
    {
        $required = $vehicle === null ? 'required' : 'sometimes';

        return [
            'make' => [$required, 'string', 'max:60'],
            'model' => [$required, 'string', 'max:60'],
            'year' => [$required, 'integer', 'min:1980', 'max:'.(now()->year + 1)],
            'color' => [$required, 'string', 'max:40'],
            'plate_number' => [$required, 'string', 'max:20', Rule::unique('vehicles', 'plate_number')->ignore($vehicle?->id)],
            'type' => [$required, Rule::in(['economy', 'standard', 'premium', 'suv'])],
            'seats' => [$required, 'integer', 'min:1', 'max:16'],
            'doors' => ['nullable', 'integer', 'min:2', 'max:6'],
            'features' => ['nullable', 'array'],
            'features.*' => [Rule::in(array_keys(FleetVehicles::FEATURES))],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:2000000'],
        ];
    }
}
