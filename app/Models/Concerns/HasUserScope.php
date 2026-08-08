<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Dot.Ehail's Fleet/Operator entity (app/Models/Fleet.php) exists now, but
 * it is deliberately not given this scope — any authenticated user must be
 * able to see the fleet list to apply to. DriverProfile keeps this scope:
 * the only genuinely single-owner tenancy relationship on it is still the
 * driver's own row (driver_profiles.user_id); FleetController explicitly
 * calls withoutGlobalScope('user') wherever a fleet owner needs to see a
 * driver profile that isn't their own. This is the direct analog of
 * Dot.Mines' HasTeamFilters / Dot.Finance's HasUserScope: every
 * model that owns a user_id column identifying a single owning user applies
 * this trait so a query against it is scoped to the authenticated user by
 * default, the same way Dot.Finance now scopes Account/Budget/Category/
 * Transaction/AiInsight — the goal is that a forgotten
 * where('user_id', ...) call (or, worse, none at all, as was previously
 * true of DriverProfilePolicy's route-model-bound `view` check) can no
 * longer leak another user's row, because the model itself never returns
 * unscoped results while a user is authenticated.
 *
 * Deliberately NOT applied to `Ride` or `RideRating`: a ride has two
 * distinct user parties (passenger_id and driver_id), not one owning user,
 * and the `/dashboard` and `/rides` views are a documented platform-wide
 * ops view by design (see RidePolicy's and RideController's docblocks and
 * wiki.md §4) — there is no single "owner" column a user-scope could
 * correctly filter on without breaking that intended behavior.
 *
 * mass-assignment still sets user_id explicitly at create time; this scope
 * only governs reads.
 */
trait HasUserScope
{
    protected static function bootHasUserScope(): void
    {
        static::addGlobalScope('user', function (Builder $builder): void {
            if (Auth::check()) {
                $builder->where($builder->getModel()->getTable().'.user_id', Auth::id());
            }
        });
    }
}
