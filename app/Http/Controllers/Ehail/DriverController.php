<?php

namespace App\Http\Controllers\Ehail;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\RideRating;
use App\Support\DriverIdentifiers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DriverController extends Controller
{
    /**
     * Show a driver's profile and ride history. Authorization is delegated
     * to DriverProfilePolicy, which restricts access to the driver
     * themselves — see the policy's docblock for the gap this closes.
     */
    public function show(int $driverProfileId): View
    {
        // Not implicitly bound: HasUserScope would 404 every viewer who is not
        // the driver, including the fleet owner and platform operators the
        // policy deliberately lets in (see FleetController::approveDriver for
        // the same reason). A viewer the policy refuses still gets a 404, so
        // a profile's existence is not revealed.
        $driverProfile = DriverProfile::withoutGlobalScope('user')->findOrFail($driverProfileId);

        abort_unless(Gate::allows('view', $driverProfile), 404);

        $driverProfile->load(['user', 'vehicles', 'documents']);

        $rides = Ride::where('driver_id', $driverProfile->user_id)
            ->with(['passenger', 'vehicle'])
            ->latest()
            ->limit(20)
            ->get();

        $received = RideRating::where('rated_user_id', $driverProfile->user_id)
            ->where('rated_role', RideRating::ROLE_DRIVER);

        $viewer = request()->user();

        // Full identity numbers only for the driver and identity reviewers; every other role sees them masked,
        // as the fleet portal and the ops driver page do (R-46 security S-04).
        $fullIdentity = $viewer->id === $driverProfile->user_id
            || ($viewer->isPlatformOperator() && app(OpsAccess::class)->can($viewer, OpsPermission::VERIFICATION_REVIEW));

        // The full licence and ID numbers are on this page. Their own owner
        // reading them is not an event; anyone else opening it is, and every
        // open is written to the append-only audit log.
        if ($viewer->id !== $driverProfile->user_id) {
            AuditLog::record('driver_profile.opened', $viewer, $driverProfile, [
                'fields' => $fullIdentity ? ['license_number', 'id_number'] : ['masked_identity'],
                'as' => $viewer->isPlatformOperator() ? 'operator' : 'fleet_owner',
            ]);
        }

        return view('drivers.show', [
            'driverProfile' => $driverProfile,
            'licenceShown' => $fullIdentity ? $driverProfile->license_number : DriverIdentifiers::mask($driverProfile->license_number),
            'idShown' => $fullIdentity ? $driverProfile->id_number : DriverIdentifiers::mask($driverProfile->id_number),
            'fullIdentity' => $fullIdentity,
            // Riders' addresses: the driver's own history, or an operator whose role reads rides (S-04).
            'rideAddresses' => $viewer->id === $driverProfile->user_id
                || ($viewer->isPlatformOperator() && app(OpsAccess::class)->can($viewer, OpsPermission::RIDES_VIEW)),
            'rides' => $rides,
            'ratingCount' => (clone $received)->count(),
            'recentRatings' => (clone $received)->whereNotNull('comment')->where('comment_status', 'visible')->with('rater')->latest()->limit(10)->get(),
            // Who left a rating is for the people who run the driver
            // (operators, the fleet owner) -- the driver sees the words, not
            // the names, and no rider can open this page at all.
            'canSeeRaters' => $viewer->isPlatformOperator() || $viewer->id === $driverProfile->fleet?->owner_user_id,
        ]);
    }

    /**
     * Flip is_online -- previously nothing anywhere in the app ever set
     * this column after profile creation (it defaults to false), so an
     * approved driver had no way to ever become eligible to accept a
     * ride (see RideLifecycleController::available()'s eligibility check).
     */
    public function toggleOnline(DriverProfile $driverProfile): RedirectResponse
    {
        Gate::authorize('update', $driverProfile);

        $driverProfile->update(['is_online' => ! $driverProfile->is_online]);

        return back()->with('status', $driverProfile->is_online ? 'You are now online.' : 'You are now offline.');
    }
}
