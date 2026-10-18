<?php

namespace App\Http\Controllers\Ehail;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Role-aware dashboard. Ride figures are always limited to the rides the
 * viewer may see (Ride::scopeVisibleTo). Revenue and driver counts are
 * business figures: platform-wide for a platform operator, scoped to their
 * own approved fleets for a fleet owner, and not shown to anyone else.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $isOperator = $user->isPlatformOperator();
        $ownsApprovedFleet = Fleet::approved()->where('owner_user_id', $user->id)->exists();
        $showFleetStats = $isOperator || $ownsApprovedFleet;

        $rides = fn () => Ride::visibleTo($user);

        $totalRides = $rides()->count();
        $activeRides = $rides()->whereIn('status', ['accepted', 'en_route', 'arrived', 'in_progress'])->count();
        $completedRides = $rides()->where('status', 'completed')->count();
        $cancelledRides = $rides()->where('status', 'cancelled')->count();
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

        return view('dashboard', compact(
            'totalRides', 'activeRides', 'completedRides', 'cancelledRides', 'requestedRides',
            'totalDrivers', 'availableDrivers', 'approvedDrivers',
            'totalRevenue', 'statusCounts', 'recentRides', 'showFleetStats'
        ));
    }
}
