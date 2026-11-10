<?php

namespace App\Http\Controllers\Fleet;

use App\Domain\Fleet\FleetVehicles;
use App\Domain\Fleet\Models\VehicleMaintenanceRecord;
use App\Domain\Payments\Money;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Maintenance: every vehicle's next service (by date and by odometer), the out-of-service flag, and the log of
 * services, repairs and inspections.
 */
class FleetMaintenanceController extends FleetController
{
    public function index(Request $request, FleetVehicles $vehicles): View
    {
        $fleet = $this->fleet($request, 'maintenance');
        $user = $request->user();
        $list = $this->access->vehicleQuery($user, $fleet)->whereNull('retired_at')->with('holder.user:id,name')->orderBy('plate_number')->get();

        $rows = $list->map(fn (Vehicle $v) => ['vehicle' => $v, 'next' => $vehicles->nextService($v, (int) config('ehail_fleet.alerts.service_due_days'), (int) config('ehail_fleet.alerts.service_due_km'))])
            ->sortBy(fn ($r) => [$r['vehicle']->out_of_service_at === null ? 1 : 0, $r['next']['overdue'] ? 0 : ($r['next']['due_soon'] ? 1 : 2), $r['vehicle']->plate_number])
            ->values();

        return view('fleet.maintenance', [
            'fleet' => $fleet,
            'fleets' => $this->access->fleetsFor($user),
            'rows' => $rows,
            'log' => VehicleMaintenanceRecord::query()->where('fleet_id', $fleet->id)->whereIn('vehicle_id', $list->pluck('id'))->with('vehicle:id,plate_number')->latest('performed_on')->latest('id')->limit(25)->get(),
            'kinds' => VehicleMaintenanceRecord::KINDS,
            'canWrite' => $this->access->can($user, $fleet, 'maintenance', true),
        ]);
    }

    public function store(Request $request, int $vehicle, FleetVehicles $vehicles): RedirectResponse|JsonResponse
    {
        $fleet = $this->fleet($request, 'maintenance', true);
        $model = Vehicle::query()->where('fleet_id', $fleet->id)->findOrFail($vehicle);
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(VehicleMaintenanceRecord::KINDS))],
            'performed_on' => ['required', 'date', 'before_or_equal:today'],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'description' => ['required', 'string', 'max:500'],
            'cost' => ['nullable', 'string', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'next_service_due_on' => ['nullable', 'date', 'after:performed_on'],
            'next_service_due_km' => ['nullable', 'integer', 'min:0', 'max:2000000'],
        ]);
        $data['cost_minor'] = isset($data['cost']) && $data['cost'] !== '' ? Money::decimalToMinor(str_replace(',', '.', $data['cost'])) : null;

        return $this->attempt($request, fn () => $vehicles->recordMaintenance($fleet, $model, $data, $request->user()), 'Maintenance recorded for '.$model->plate_number.'.');
    }

    public function outOfService(Request $request, int $vehicle, FleetVehicles $vehicles): RedirectResponse|JsonResponse
    {
        $fleet = $this->fleet($request, 'maintenance', true);
        $model = Vehicle::query()->where('fleet_id', $fleet->id)->findOrFail($vehicle);
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $outcome = null;
        $response = $this->attempt($request, function () use (&$outcome, $vehicles, $fleet, $model, $data, $request) {
            return $outcome = $vehicles->takeOutOfService($fleet, $model, $data['reason'], $request->user());
        }, $model->plate_number.' is out of service. Nobody can go online in it until you return it to service.');

        if ($outcome !== null && $response instanceof RedirectResponse) {
            $response->with('status', $model->plate_number.' is out of service.'.match (true) {
                $outcome['taken_offline'] => ' Its driver was online in it and has been taken offline.',
                $outcome['offline_after_trip'] => ' Its driver is on a trip; they are taken offline when it ends.',
                default => ' Nobody can go online in it until you return it to service.',
            });
        }

        return $response;
    }

    public function backInService(Request $request, int $vehicle, FleetVehicles $vehicles): RedirectResponse|JsonResponse
    {
        $fleet = $this->fleet($request, 'maintenance', true);
        $model = Vehicle::query()->where('fleet_id', $fleet->id)->findOrFail($vehicle);

        return $this->attempt($request, fn () => $vehicles->returnToService($fleet, $model, $request->user()), $model->plate_number.' is back in service.');
    }
}
