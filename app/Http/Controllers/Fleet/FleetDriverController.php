<?php

namespace App\Http\Controllers\Fleet;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Earnings\EarningsService;
use App\Domain\Fleet\DocumentOverview;
use App\Domain\Fleet\DriverApplications;
use App\Domain\Fleet\FleetPerformance;
use App\Domain\Fleet\Models\FleetVehicleAssignment;
use App\Domain\Fleet\Models\FleetVehiclePermit;
use App\Models\DriverProfile;
use App\Support\DriverIdentifiers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fleet drivers: applications waiting for a decision, the fleet's drivers with their factual performance, and one
 * driver's page (status of their documents, vehicles they may drive, performance). Identity numbers appear masked
 * only for platform operators and for an owner whose fleet was granted identity-review rights (D-1205).
 */
class FleetDriverController extends FleetController
{
    public function index(Request $request, FleetPerformance $performance, DriverApplications $applications): View
    {
        $fleet = $this->fleet($request, 'drivers');
        $user = $request->user();
        $period = in_array($request->query('period'), ['day', 'week', 'month'], true) ? $request->query('period') : 'week';
        [$from, $to] = EarningsService::periodBounds($period);

        $pending = DriverProfile::withoutGlobalScope('user')->where('fleet_id', $fleet->id)->where('status', 'pending')->with('user:id,name')->oldest()->get();
        $rejected = $this->access->seesDriverRecords($user, $fleet)
            ? DriverProfile::withoutGlobalScope('user')->where('fleet_id', $fleet->id)->where('status', 'rejected')->with('user:id,name')->latest('reviewed_at')->limit(10)->get()
            : collect();

        $states = DriverAvailability::query()->whereIn('user_id', $this->access->driverUserIds($user, $fleet))->get()->keyBy('user_id');
        $holding = FleetVehicleAssignment::query()->open()->where('fleet_id', $fleet->id)->with('vehicle:id,plate_number')->get()->groupBy('driver_profile_id');

        return view('fleet.drivers.index', [
            'fleet' => $fleet,
            'fleets' => $this->access->fleetsFor($user),
            'period' => $period,
            'performance' => $performance->forFleet($user, $fleet, $from, $to),
            'pending' => $pending,
            'rejected' => $rejected,
            'states' => $states,
            'holding' => $holding,
            'canDecide' => fn (DriverProfile $p) => $applications->canDecide($user, $fleet, $p),
            'masked' => $this->access->seesMaskedIdentity($user, $fleet),
            'mask' => fn (?string $value) => DriverIdentifiers::mask($value),
        ]);
    }

    public function show(Request $request, int $profile, DocumentOverview $documents, FleetPerformance $performance): View
    {
        $fleet = $this->fleet($request, 'drivers');
        $user = $request->user();
        $driver = $this->access->driverQuery($user, $fleet)->with('user:id,name,created_at')->findOrFail($profile);
        [$from, $to] = EarningsService::periodBounds('month');
        $rows = collect($performance->forFleet($user, $fleet, $from, $to)['rows'])->firstWhere('profile_id', $driver->id);

        return view('fleet.drivers.show', [
            'fleet' => $fleet,
            'fleets' => $this->access->fleetsFor($user),
            'driver' => $driver,
            'documents' => in_array($driver->status, ['approved', 'suspended'], true) ? $documents->driverStatus($driver) : null,
            'performance' => $rows,
            'availability' => DriverAvailability::query()->where('user_id', $driver->user_id)->first(),
            'permits' => FleetVehiclePermit::query()->active()->where('driver_profile_id', $driver->id)->where('fleet_id', $fleet->id)->with('vehicle')->get(),
            'assignments' => FleetVehicleAssignment::query()->where('driver_profile_id', $driver->id)->where('fleet_id', $fleet->id)->with('vehicle:id,plate_number')->latest('started_at')->limit(10)->get(),
            'ownVehicles' => $driver->vehicles()->where('owned_by', 'driver')->get(),
            'masked' => $this->access->seesMaskedIdentity($user, $fleet),
            'canWrite' => $this->access->can($user, $fleet, 'vehicles', true),
        ]);
    }

    public function approve(Request $request, int $profile, DriverApplications $applications): RedirectResponse|JsonResponse
    {
        $fleet = $this->fleet($request, 'drivers');

        return $this->attempt($request, fn () => $applications->approve($fleet, $profile, $request->user()), 'Driver approved. They can go online once their documents are verified.');
    }

    public function reject(Request $request, int $profile, DriverApplications $applications): RedirectResponse|JsonResponse
    {
        $fleet = $this->fleet($request, 'drivers');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->attempt($request, fn () => $applications->reject($fleet, $profile, $request->user(), $data['reason']), 'Application declined.');
    }
}
