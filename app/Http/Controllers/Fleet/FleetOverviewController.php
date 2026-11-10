<?php

namespace App\Http\Controllers\Fleet;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Earnings\EarningsService;
use App\Domain\Fleet\FleetAlerts;
use App\Domain\Fleet\FleetEarnings;
use App\Domain\Fleet\FleetVehicles;
use App\Domain\Verification\DriverEligibility;
use App\Enums\RideStatus;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * /fleet: which of the fleet's vehicles can work today and what is stopping the others, with the counts, the
 * alerts that need action and the applications waiting. /fleet/alerts lists every alert.
 */
class FleetOverviewController extends FleetController
{
    public function __invoke(Request $request, FleetAlerts $alerts, FleetEarnings $earnings, FleetVehicles $vehicles, DriverEligibility $eligibility): View
    {
        $user = $request->user();
        $fleet = $this->access->current($request);

        if ($fleet === null) {
            return view('fleet.none');
        }

        $drivers = $this->access->drivers($user, $fleet);
        $driverIds = $drivers->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $working = DriverAvailability::query()->whereIn('user_id', $driverIds)->where('state', '!=', DriverAvailability::OFFLINE)->get()->keyBy('user_id');

        $yard = $this->access->vehicleQuery($user, $fleet)->whereNull('retired_at')->with('driverProfile.user:id,name')->orderBy('plate_number')->get()
            ->map(fn (Vehicle $v) => $this->yardRow($v, $working, $eligibility, $vehicles));

        [$from, $to] = EarningsService::periodBounds('week');
        $canEarnings = $this->access->can($user, $fleet, 'earnings');
        $week = $canEarnings ? $earnings->statement($user, $fleet, 'week') : null;

        return view('fleet.overview', [
            'fleet' => $fleet,
            'fleets' => $this->access->fleetsFor($user),
            'drivers' => $drivers,
            'yard' => $yard,
            'onlineNow' => $working->count(),
            'tripsThisWeek' => Ride::query()->whereIn('driver_id', $driverIds)->where('status', RideStatus::Completed->value)->whereBetween('completed_at', [$from, $to])->count(),
            'week' => $week,
            'alerts' => array_slice($alerts->forFleet($user, $fleet), 0, 6),
            'alertCount' => count($alerts->forFleet($user, $fleet)),
            'applications' => $this->access->can($user, $fleet, 'drivers')
                ? DriverProfile::withoutGlobalScope('user')->where('fleet_id', $fleet->id)->where('status', 'pending')->with('user:id,name')->oldest()->limit(5)->get()
                : collect(),
            'access' => $this->access,
        ]);
    }

    public function alerts(Request $request, FleetAlerts $alerts): View
    {
        $fleet = $this->access->current($request);
        abort_if($fleet === null, 404);

        return view('fleet.alerts', [
            'fleet' => $fleet,
            'fleets' => $this->access->fleetsFor($request->user()),
            'alerts' => $alerts->forFleet($request->user(), $fleet),
        ]);
    }

    /**
     * @param  Collection<int, DriverAvailability>  $working
     * @return array<string, mixed>
     */
    private function yardRow(Vehicle $vehicle, $working, DriverEligibility $eligibility, FleetVehicles $vehicles): array
    {
        $holder = $vehicle->driverProfile;
        $holderUser = $holder?->user;
        $availability = $holderUser ? $working->get($holderUser->id) : null;
        $inIt = $availability !== null && (int) $availability->vehicle_id === (int) $vehicle->id;

        if ($vehicle->out_of_service_at !== null) {
            $state = ['key' => 'out', 'label' => 'Out of service', 'tone' => 'stop', 'note' => $vehicle->out_of_service_reason ?: 'No reason recorded'];
        } elseif ($holder === null) {
            $state = ['key' => 'unassigned', 'label' => 'No driver', 'tone' => 'neutral', 'note' => 'Give it to a permitted driver'];
        } elseif ($inIt) {
            $state = ['key' => 'working', 'label' => match ($availability->state) {
                DriverAvailability::ON_TRIP => 'On a trip',
                DriverAvailability::PAUSED => 'Paused',
                default => 'Online',
            }, 'tone' => 'go', 'note' => 'Since '.($availability->since?->format('H:i') ?? 'earlier')];
        } else {
            $result = $holderUser ? $eligibility->canGoOnline($holderUser, $vehicle) : null;
            $state = $result !== null && $result->allowed()
                ? ['key' => 'ready', 'label' => $vehicle->is_active ? 'Ready, offline' : 'Ready, not selected', 'tone' => 'info', 'note' => $vehicle->is_active ? 'Can go online now' : 'The driver drives another vehicle now']
                : ['key' => 'blocked', 'label' => 'Cannot go online', 'tone' => 'caution', 'note' => $result?->reasons[0]['message'] ?? 'No driver'];
        }

        $service = $vehicles->nextService($vehicle, (int) config('ehail_fleet.alerts.service_due_days'), (int) config('ehail_fleet.alerts.service_due_km'));

        return [
            'vehicle' => $vehicle,
            'holder' => $holderUser?->name,
            'state' => $state,
            'service' => $service,
        ];
    }
}
