<?php

namespace App\Providers;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Fleet\FleetAccess;
use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Ops\Audit\OpsAudit;
use App\Domain\Ops\Availability\AvailabilitySpanRecorder;
use App\Domain\Ops\Http\OpsPrivilegeGuard;
use App\Domain\Verification\Models\VerificationDocument;
use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 operations centre (ops agent): operator roles and their enforcement, measured online time, and the audit
 * helpers. Gates and middleware are registered here, never in route files (route:cache).
 */
class EhailOpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(OpsAccess::class);
        $this->app->singleton(OpsAudit::class);
    }

    public function boot(Router $router): void
    {
        // Narrow the operator-flag shortcuts that other domains take outside the `operator` middleware.
        $router->pushMiddlewareToGroup('web', OpsPrivilegeGuard::class);

        foreach (OpsPermission::all() as $permission) {
            Gate::define('ops.'.$permission, fn (User $user) => $this->app->make(OpsAccess::class)->can($user, $permission));
        }

        Gate::before(fn (User $user, string $ability, array $arguments) => $this->narrowOperatorShortcuts($user, $ability, $arguments));

        DriverAvailability::observe(AvailabilitySpanRecorder::class);
    }

    /**
     * Policies in other domains allow any platform operator (identity documents, driver profiles, earnings,
     * payouts, receipts). Here an operator who is not the record's own person also needs the matching role
     * permission; null leaves the domain's own decision untouched.
     *
     * @param  array<int, mixed>  $arguments
     */
    private function narrowOperatorShortcuts(User $user, string $ability, array $arguments): ?bool
    {
        if (! $user->isPlatformOperator()) {
            return null;
        }

        $subject = $arguments[0] ?? null;
        $needs = match (true) {
            $subject instanceof VerificationDocument => $user->id === $subject->user_id ? null : [OpsPermission::VERIFICATION_REVIEW],
            $subject instanceof DriverDocument => $this->ownsDriverDocument($user, $subject) ? null : [OpsPermission::VERIFICATION_REVIEW],
            $subject instanceof DriverProfile && $ability === 'view' => $user->id === $subject->user_id ? null : [OpsPermission::DRIVERS_VIEW],
            // Fleet pages and driver applications (R-46 security S-02): an operator who is not the fleet's own
            // owner or manager needs fleets.review, as on /ops/fleets.
            $subject instanceof Fleet && in_array($ability, ['view', 'viewVehicles', 'reviewDriver'], true) => $this->app->make(FleetAccess::class)->isMember($user, $subject) ? null : [OpsPermission::FLEETS_REVIEW],
            $ability === 'money.view-receipt' => ($subject?->passenger_id ?? null) === $user->id ? null : [OpsPermission::FINANCE_VIEW, OpsPermission::SUPPORT_VIEW],
            $ability === 'money.view-earning' => ($subject?->driver_id ?? null) === $user->id ? null : [OpsPermission::FINANCE_VIEW, OpsPermission::DRIVERS_EARNINGS],
            $ability === 'money.view-payout' => ($subject?->payee_user_id ?? null) === $user->id ? null : [OpsPermission::FINANCE_VIEW, OpsPermission::DRIVERS_EARNINGS],
            $ability === 'money.view-fleet-earnings' => ($subject?->owner_user_id ?? null) === $user->id ? null : [OpsPermission::FINANCE_VIEW],
            default => null,
        };

        if ($needs === null) {
            return null;
        }

        return $this->app->make(OpsAccess::class)->canAny($user, $needs) ? null : false;
    }

    private function ownsDriverDocument(User $user, DriverDocument $document): bool
    {
        return $document->driverProfile()->withoutGlobalScope('user')->value('user_id') === $user->id;
    }
}
