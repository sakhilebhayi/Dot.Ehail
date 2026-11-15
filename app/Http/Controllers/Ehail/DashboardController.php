<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Earnings\EarningsService;
use App\Domain\Fleet\FleetAccess;
use App\Domain\Fleet\FleetAlerts;
use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Live\LiveBoard;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Vehicle;
use App\Models\Ride;
use App\Models\Team;
use App\Support\Ui\BookingReadiness;
use App\Support\Ui\LandingAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * The signed-in landing (R-50): one dominant action per role (App\Support\Ui\LandingAction), an honest answer to
 * "can a passenger book?" (App\Support\Ui\BookingReadiness), then the figures the old dashboard carried.
 *
 * Role-aware dashboard. Ride figures are always limited to the rides the
 * viewer may see (Ride::scopeVisibleTo). Revenue and driver counts are
 * business figures: platform-wide for a platform operator, scoped to their
 * own approved fleets for a fleet owner, and not shown to anyone else.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, BookingReadiness $booking, LandingAction $landing, OpsAccess $ops, FleetAccess $fleets, LiveBoard $board, FleetAlerts $alerts, EarningsService $earnings): View
    {
        $user = $request->user();

        // A person with a trip in progress must never wait on InfoDot for the switcher (R-51): cache only.
        $activeTrip = Ride::query()
            ->where(fn ($q) => $q->where('active_passenger_id', $user->id)->orWhere('active_driver_id', $user->id))
            ->first();
        if ($activeTrip !== null) {
            $request->attributes->set(\App\Support\Ui\AppsSwitcher::CACHE_ONLY_ATTRIBUTE, true);
        }

        $isOperator = $user->isPlatformOperator();
        $ownsApprovedFleet = Fleet::approved()->where('owner_user_id', $user->id)->exists();
        $showFleetStats = $isOperator || $ownsApprovedFleet;

        $rides = fn () => Ride::visibleTo($user);

        $totalRides = $rides()->count();
        $activeRides = $rides()->whereIn('status', ['driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress'])->count();
        $completedRides = $rides()->where('status', 'completed')->count();
        $cancelledRides = $rides()->whereIn('status', ['cancelled_by_passenger', 'cancelled_by_driver', 'cancelled_by_operator'])->count();
        $requestedRides = $rides()->where('status', 'requested')->count();

        $totalDrivers = $availableDrivers = $approvedDrivers = 0;
        $totalRevenue = 0.0;

        if ($showFleetStats) {
            // DriverProfile carries HasUserScope (a driver's own profile is
            // single-owner tenant data -- see
            // app/Models/Concerns/HasUserScope.php), so these aggregates
            // bypass it explicitly: platform-wide for an operator, limited
            // to owned approved fleets for a fleet owner.
            $profiles = fn () => DriverProfile::withoutGlobalScope('user')
                ->when(! $isOperator, fn ($q) => $q->whereIn('fleet_id', Fleet::approved()->where('owner_user_id', $user->id)->select('id')));

            $totalDrivers = $profiles()->count();
            $availableDrivers = $profiles()->where('is_online', true)->count();
            $approvedDrivers = $profiles()->where('status', 'approved')->count();

            // Revenue counts only completed rides that carry a final fare
            // (a legacy completed ride may have NULL), and is never null.
            $totalRevenue = (float) Ride::where('status', 'completed')
                ->whereNotNull('final_fare')
                ->when(! $isOperator, fn ($q) => $q->drivenInFleetsOwnedBy($user))
                ->sum('final_fare');
        }

        $statusCounts = $rides()->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $recentRides = $rides()->with(['driver', 'passenger', 'vehicle'])
            ->latest()
            ->limit(10)
            ->get();

        // The selected ride is only ever one of the rows loaded above: an arbitrary ?ride= id falls back to the first.
        $requested = (int) $request->query('ride');
        $selected = $recentRides->firstWhere('id', $requested) ?? $recentRides->first();

        $landingAction = $landing->for($user, $booking);
        $role = $landingAction['role'];

        // Operator, booking open: the three counts that need a decision (same payload ops.live polls, cached 15 s).
        $decisions = null;
        if ($role === 'operator' && $booking->isOpen() && $ops->allowsRoute($user, 'ops.live')) {
            $decisions = Cache::remember('ehail:dash:decisions:'.$user->id, 15, fn () => array_intersect_key(
                $board->payload($user)['counts'],
                array_flip(['needs_review', 'stuck', 'searching', 'incidents'])
            ));
        }

        // Fleet owner: a light digest of the current fleet (counts only; alerts cached 60 s).
        $fleetDigest = null;
        if ($role === 'fleet' && ($fleet = $fleets->current($request)) !== null) {
            $driverIds = $fleets->driverUserIds($user, $fleet);
            $fleetDigest = [
                'fleet' => $fleet->name,
                'vehicles' => $fleets->vehicleQuery($user, $fleet)->whereNull('retired_at')->count(),
                'online' => DriverAvailability::query()->whereIn('user_id', $driverIds)->where('state', '!=', DriverAvailability::OFFLINE)->count(),
                'applications' => DriverProfile::withoutGlobalScope('user')->where('fleet_id', $fleet->id)->where('status', 'pending')->count(),
                'alerts' => Cache::remember('ehail:dash:alerts:'.$user->id.':'.$fleet->id, 60, fn () => count($alerts->forFleet($user, $fleet))),
            ];
        }

        // Driver: today's net earnings and the vehicle in use.
        $driverToday = null;
        if ($role === 'driver') {
            $day = $earnings->forDriver($user, 'day');
            $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $user->id)->first();
            $driverToday = [
                'net_minor' => (int) $day['totals']['net_minor'],
                'trips' => (int) $day['totals']['trips'],
                'online' => (bool) $profile?->is_online,
                'approved' => $profile?->status === 'approved',
                'plate' => $profile ? Vehicle::query()->where('driver_profile_id', $profile->id)->where('is_active', true)->value('plate_number') : null,
            ];
        }

        $team = $user->currentTeam;

        return view('dashboard', [
            'firstName' => trim((string) strtok(trim((string) $user->name), ' ')),
            // A personal team is the person, not a company: only a real company name is greeted.
            'companyName' => $team instanceof Team && ! $team->personal_team ? trim((string) $team->name) : '',
            'landing' => $landingAction,
            'selected' => $selected,
            'activeTrip' => $activeTrip,
            'decisions' => $decisions,
            'fleetDigest' => $fleetDigest,
            'driverToday' => $driverToday,
            'booking' => $booking,
            'infodotUrl' => rtrim((string) config('ecosystem.platforms.infodot.url'), '/'),
        ] + compact(
            'totalRides', 'activeRides', 'completedRides', 'cancelledRides', 'requestedRides',
            'totalDrivers', 'availableDrivers', 'approvedDrivers',
            'totalRevenue', 'statusCounts', 'recentRides', 'showFleetStats'
        ));
    }
}
