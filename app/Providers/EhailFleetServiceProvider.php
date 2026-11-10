<?php

namespace App\Providers;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Fleet\FleetAccess;
use App\Domain\Fleet\FleetAwareDriverEligibility;
use App\Domain\Fleet\FleetPayeeResolver;
use App\Domain\Fleet\FleetRegistration;
use App\Domain\Fleet\Listeners\RecordOnlineSession;
use App\Domain\Fleet\Listeners\ReleaseOutOfServiceVehicles;
use App\Domain\Fleet\Seams\RehomeVehicleDocuments;
use App\Domain\Fleet\Seams\VehicleDocumentCustody;
use App\Domain\Payments\Seams\PayeeResolver;
use App\Domain\Rides\Events\RideCancelled;
use App\Domain\Rides\Events\RideFailed;
use App\Domain\Rides\Events\RideNoShow;
use App\Domain\Rides\Events\TripCompleted;
use App\Domain\Verification\DriverEligibility;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Vehicle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 fleet domain (docs/R46_FLEET.md): bindings, the go-online decoration, listeners and limiters.
 * Never in route files (route:cache).
 *
 *  - PayeeResolver: per-fleet payee arrangement (money's seam for "wave-2 fleet arrangements"). Bound outright:
 *    money binds its config default with bindIf, and the integration provider leaves this seam to the fleet.
 *  - DriverEligibility (verification's go-online gate) is decorated, not replaced: out-of-service, retired and
 *    unassigned fleet vehicles add reasons on top of verification's own.
 *  - VehicleDocumentCustody: bindIf, so the safety domain can replace it.
 */
class EhailFleetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(FleetAccess::class);
        $this->app->bind(PayeeResolver::class, FleetPayeeResolver::class);
        $this->app->bindIf(VehicleDocumentCustody::class, RehomeVehicleDocuments::class);
        $this->app->extend(DriverEligibility::class, fn (DriverEligibility $inner) => $inner instanceof FleetAwareDriverEligibility ? $inner : new FleetAwareDriverEligibility($inner));
    }

    public function boot(): void
    {
        // Every vehicle belongs to the fleet of the driver who registers it (the fleet portal sets it itself).
        Vehicle::creating(function (Vehicle $vehicle): void {
            if ($vehicle->fleet_id === null && $vehicle->driver_profile_id !== null) {
                $vehicle->fleet_id = DriverProfile::withoutGlobalScope('user')->whereKey($vehicle->driver_profile_id)->value('fleet_id');
            }
        });

        // However a fleet is created (portal, legacy driver application), its owner is a member; approval is dated.
        Fleet::created(fn (Fleet $fleet) => app(FleetRegistration::class)->ensureOwnerMember($fleet));
        Fleet::updating(function (Fleet $fleet): void {
            if ($fleet->isDirty('status') && $fleet->status === Fleet::STATUS_APPROVED && $fleet->approved_at === null) {
                $fleet->approved_at = now();
            }
        });

        DriverAvailability::saved(fn (DriverAvailability $row) => app(RecordOnlineSession::class)->handle($row));

        Event::listen([TripCompleted::class, RideCancelled::class, RideNoShow::class, RideFailed::class], [ReleaseOutOfServiceVehicles::class, 'handle']);

        RateLimiter::for('fleet-writes', fn (Request $request) => Limit::perMinute(60)->by('fleet-writes:'.$request->user()?->id));
    }
}
