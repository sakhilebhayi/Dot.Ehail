<?php

namespace App\Domain\Fleet;

use App\Domain\Fleet\Models\FleetMember;
use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Who may see and do what in a fleet, and the isolation queries every fleet page uses.
 *
 * Areas: drivers, vehicles, maintenance, documents, earnings, trips (managers get a chosen subset), settings and
 * members (owner only). Platform operators may read every area of every fleet and change nothing here (their
 * actions live in the operations centre; deciding a driver application keeps its legacy policy).
 *
 * Isolation: a fleet page only ever reads driver profiles whose fleet_id is this fleet, vehicles whose fleet_id is
 * this fleet, rides driven by those drivers or in those vehicles, and earnings recorded for this fleet. While the
 * fleet is pending operator approval its owner is an applicant: other people's data (their profiles beyond the
 * application, their vehicles, trips and earnings) stays hidden, as it was on the legacy fleet page.
 */
final class FleetAccess
{
    public const AREAS = ['drivers', 'vehicles', 'maintenance', 'documents', 'earnings', 'trips', 'settings', 'members'];

    public const OWNER_ONLY = ['settings', 'members'];

    public const SESSION_KEY = 'fleet.current';

    /**
     * Fleets this person works on (owner or active member), by name.
     *
     * @return Collection<int, Fleet>
     */
    public function fleetsFor(User $user): Collection
    {
        $memberFleetIds = FleetMember::query()->active()->where('user_id', $user->id)->pluck('fleet_id');

        return Fleet::query()
            ->where(fn ($q) => $q->where('owner_user_id', $user->id)->orWhereIn('id', $memberFleetIds))
            ->orderBy('name')
            ->get();
    }

    public function isOwner(User $user, Fleet $fleet): bool
    {
        return (int) $fleet->owner_user_id === (int) $user->id;
    }

    /**
     * Not memoised: controllers are kept between requests in long-running workers and tests, and a revoked
     * manager must lose access on the very next request.
     */
    public function membership(User $user, Fleet $fleet): ?FleetMember
    {
        return FleetMember::query()->active()->where('fleet_id', $fleet->id)->where('user_id', $user->id)->first();
    }

    public function isMember(User $user, Fleet $fleet): bool
    {
        return $this->isOwner($user, $fleet) || $this->membership($user, $fleet) !== null;
    }

    /**
     * May this person use an area of this fleet? $write: change something there (operators never write here).
     */
    public function can(User $user, Fleet $fleet, string $area, bool $write = false): bool
    {
        if ($this->isOwner($user, $fleet)) {
            return true;
        }

        $member = $this->membership($user, $fleet);

        if ($member !== null) {
            return ! in_array($area, self::OWNER_ONLY, true) && $member->hasScope($area);
        }

        return ! $write && $area !== 'members' && $this->operatorMayRead($user, $area);
    }

    public function canView(User $user, Fleet $fleet): bool
    {
        return $this->isMember($user, $fleet) || $this->operatorMayRead($user);
    }

    /**
     * Operators read fleets through their role (R-46 security S-02): fleets.review for the fleet's records, and
     * finance.view as well for its earnings, the same permissions /ops/fleets and the money pages ask for.
     */
    public function operatorMayRead(User $user, ?string $area = null): bool
    {
        if (! $user->isPlatformOperator()) {
            return false;
        }

        $access = app(OpsAccess::class);

        return $access->can($user, OpsPermission::FLEETS_REVIEW)
            && ($area !== 'earnings' || $access->can($user, OpsPermission::FINANCE_VIEW));
    }

    /**
     * Whether other drivers' records are visible to this person in this fleet (see the class comment).
     */
    public function seesDriverRecords(User $user, Fleet $fleet): bool
    {
        return $fleet->isApproved() || $this->operatorMayRead($user);
    }

    /**
     * Identity numbers (masked) on applications: platform operators, and a fleet owner only after an operator
     * granted this fleet identity-review rights. Managers never; default for owners is status only.
     */
    public function seesMaskedIdentity(User $user, Fleet $fleet): bool
    {
        return $this->operatorMayRead($user) || ($this->isOwner($user, $fleet) && $fleet->identityReviewGranted());
    }

    /**
     * The fleet a request is about: ?fleet=<id> (remembered in the session), else the remembered one, else the
     * first fleet this person works on. Never a fleet the person cannot view.
     */
    public function current(Request $request): ?Fleet
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $requested = $request->query('fleet');

        if (is_scalar($requested) && ctype_digit((string) $requested)) {
            $fleet = Fleet::query()->find((int) $requested);

            if ($fleet !== null && $this->canView($user, $fleet)) {
                $request->session()->put(self::SESSION_KEY, $fleet->id);

                return $fleet;
            }

            return null;
        }

        $remembered = $request->session()->get(self::SESSION_KEY);

        if ($remembered !== null) {
            $fleet = Fleet::query()->find((int) $remembered);

            if ($fleet !== null && $this->canView($user, $fleet)) {
                return $fleet;
            }

            $request->session()->forget(self::SESSION_KEY);
        }

        return $this->fleetsFor($user)->first();
    }

    /**
     * Driver profiles that are this fleet's drivers (approved or suspended), visible to this person.
     *
     * @return Collection<int, DriverProfile>
     */
    public function drivers(User $viewer, Fleet $fleet): Collection
    {
        return $this->driverQuery($viewer, $fleet)
            ->whereIn('status', ['approved', 'suspended'])
            ->with('user:id,name')
            ->get()
            ->sortBy(fn (DriverProfile $p) => mb_strtolower((string) $p->user?->name))
            ->values();
    }

    /**
     * Driver user ids of this fleet visible to this person (any status: earnings and trips of a driver who has
     * since been suspended still belong to the fleet's history).
     *
     * @return list<int>
     */
    public function driverUserIds(User $viewer, Fleet $fleet): array
    {
        return $this->driverQuery($viewer, $fleet)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return Builder<DriverProfile>
     */
    public function driverQuery(User $viewer, Fleet $fleet): Builder
    {
        return DriverProfile::withoutGlobalScope('user')
            ->where('fleet_id', $fleet->id)
            ->when(! $this->seesDriverRecords($viewer, $fleet), fn ($q) => $q->where('user_id', $fleet->owner_user_id));
    }

    /**
     * This fleet's vehicles visible to this person: every vehicle the fleet holds or its drivers registered, or,
     * while the fleet is pending, only fleet-held vehicles and the owner's own.
     *
     * @return Builder<Vehicle>
     */
    public function vehicleQuery(User $viewer, Fleet $fleet): Builder
    {
        return Vehicle::query()
            ->where('fleet_id', $fleet->id)
            ->when(! $this->seesDriverRecords($viewer, $fleet), fn ($q) => $q->where(fn ($q) => $q
                ->where('owned_by', 'fleet')
                ->orWhereIn('driver_profile_id', DriverProfile::withoutGlobalScope('user')->where('user_id', $fleet->owner_user_id)->select('id'))));
    }
}
