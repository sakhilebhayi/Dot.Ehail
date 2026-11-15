<?php

namespace App\Domain\Search;

use App\Domain\Business\Abilities;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessTrip;
use App\Domain\Business\PortalAccess;
use App\Domain\Fleet\FleetAccess;
use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Passenger\Models\SavedPlace;
use App\Enums\RideStatus;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Ui\RoleHome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The header search of every signed-in shell (R-52). One service, one scope per surface; the SURFACE is decided by the
 * server from what the person really is, never by the request alone: a request may NAME a surface, and a surface the
 * person does not hold is refused (the controller answers 403), so no parameter widens what is searched.
 *
 *   passenger  own trips (as the rider) and own saved places
 *   driver     own trips (as the assigned driver); the rider is never searched or shown
 *   fleet      the current fleet's vehicles and drivers, as far as the person's fleet role allows (FleetAccess)
 *   ops        rides, drivers, vehicles and fleets, each only where the operator's ops role allows its route (OpsAccess)
 *   business   the current business account's trips: a traveller sees their own, an admin / manager / finance viewer all
 *
 * No row carries a phone number or an e-mail address. A row is {href, label, code?, tag?} and nothing else.
 */
final class HeaderSearch
{
    public const SURFACES = ['passenger', 'driver', 'fleet', 'ops', 'business'];

    public const SUGGEST_LIMIT = 4;

    public const PAGE_LIMIT = 25;

    public function __construct(
        private readonly RoleHome $roles,
        private readonly OpsAccess $ops,
        private readonly FleetAccess $fleets,
        private readonly PortalAccess $portal,
    ) {}

    /** @return list<string> the surfaces this person may search, in a stable order */
    public function allowed(User $user): array
    {
        $allowed = ['passenger'];

        if ($this->roles->isDriver($user)) {
            $allowed[] = 'driver';
        }
        if ($this->fleets->fleetsFor($user)->isNotEmpty()) {
            $allowed[] = 'fleet';
        }
        if ($this->ops->isOperator($user)) {
            $allowed[] = 'ops';
        }
        if ($this->portal->memberFor($user) !== null) {
            $allowed[] = 'business';
        }

        return $allowed;
    }

    /** The surface to search: the one asked for if the person holds it, null if they asked for one they do not, else their primary. */
    public function resolve(User $user, mixed $requested): ?string
    {
        $allowed = $this->allowed($user);

        if (is_string($requested) && $requested !== '') {
            return in_array($requested, $allowed, true) ? $requested : null;
        }

        $primary = $this->roles->primarySurface($user);

        return in_array($primary, $allowed, true) ? $primary : $allowed[0];
    }

    /**
     * @return list<array{key: string, head: string, rows: list<array{href: string, label: string, code: ?string, tag: ?string}>, more: bool}>
     */
    public function search(Request $request, User $user, string $surface, string $q, int $limit): array
    {
        $q = SearchText::clean($q);

        if (mb_strlen($q) < SearchText::MIN || ! in_array($surface, $this->allowed($user), true)) {
            return [];
        }

        $groups = match ($surface) {
            'passenger' => $this->passenger($user, $q, $limit),
            'driver' => $this->driver($user, $q, $limit),
            'fleet' => $this->fleet($request, $user, $q, $limit),
            'ops' => $this->ops($user, $q, $limit),
            'business' => $this->business($user, $q, $limit),
            default => [],
        };

        return array_values(array_filter($groups, fn (array $group) => $group['rows'] !== []));
    }

    // ---------------------------------------------------------------- passenger and driver

    private function passenger(User $user, string $q, int $limit): array
    {
        $rides = Ride::query()->where('passenger_id', $user->id)
            ->where(function (Builder $w) use ($q) {
                $this->rideMatch($w, $q);
                // The driver's name is on the rider's own trip page already (the older ride list searches it too).
                $w->orWhereIn('driver_id', User::query()->select('id')->whereRaw('lower(name) like ?', [SearchText::like($q)]));
            })
            ->latest('id')->limit($limit + 1)->get();

        $places = SavedPlace::query()->where('user_id', $user->id)
            ->where(fn (Builder $w) => SearchText::whereLike(SearchText::whereLike($w, 'label', $q), 'address', $q))
            ->orderBy('label')->limit($limit + 1)->get();

        return [
            $this->group('rides', 'Your trips', $rides, $limit, fn (Ride $r) => $this->row(route('ride.trip', $r), $this->route($r), '#'.$r->id, $this->status($r))),
            $this->group('places', 'Saved places', $places, $limit, fn (SavedPlace $p) => $this->row(route('ride.account'), $p->label, null, Str::limit($p->address, 40))),
        ];
    }

    private function driver(User $user, string $q, int $limit): array
    {
        $rides = Ride::query()->where('driver_id', $user->id)
            ->where(fn (Builder $w) => $this->rideMatch($w, $q))
            ->latest('id')->limit($limit + 1)->get();

        return [$this->group('trips', 'Your trips', $rides, $limit, fn (Ride $r) => $this->row(route('drive.trips.show', $r), $this->route($r), '#'.$r->id, $this->status($r)))];
    }

    // ---------------------------------------------------------------- fleet

    private function fleet(Request $request, User $user, string $q, int $limit): array
    {
        $fleet = $this->fleets->current($request);

        if ($fleet === null) {
            return [];
        }

        $groups = [];

        if ($this->fleets->can($user, $fleet, 'vehicles')) {
            $vehicles = $this->fleets->vehicleQuery($user, $fleet)->whereNull('retired_at')
                ->where(function (Builder $w) use ($q) {
                    SearchText::whereLike($w, 'plate_number', $q);
                    SearchText::whereLike($w, 'make', $q);
                    SearchText::whereLike($w, 'model', $q);
                })
                ->orderBy('plate_number')->limit($limit + 1)->get();
            $groups[] = $this->group('vehicles', 'Vehicles', $vehicles, $limit, fn (Vehicle $v) => $this->row(route('fleet.vehicles.show', $v), trim($v->make.' '.$v->model), $v->plate_number, $v->color ?: null));
        }

        if ($this->fleets->can($user, $fleet, 'drivers')) {
            $drivers = $this->fleets->driverQuery($user, $fleet)->with('user:id,name')
                ->whereIn('user_id', User::query()->select('id')->whereRaw('lower(name) like ?', [SearchText::like($q)]))
                ->limit($limit + 1)->get();
            $groups[] = $this->group('drivers', 'Drivers', $drivers, $limit, fn (DriverProfile $d) => $this->row(route('fleet.drivers.show', $d->id), $d->user?->name ?? 'Driver', null, Str::headline((string) $d->status)));
        }

        return $groups;
    }

    // ---------------------------------------------------------------- operations

    private function ops(User $user, string $q, int $limit): array
    {
        $groups = [];

        if ($this->ops->allowsRoute($user, 'ops.rides')) {
            $rides = Ride::query()
                ->where(function (Builder $w) use ($q) {
                    $this->rideMatch($w, $q);
                    // The ride desk's own list searches the rider's and the driver's name; so does this.
                    $w->orWhereIn('passenger_id', User::query()->select('id')->whereRaw('lower(name) like ?', [SearchText::like($q)]))
                        ->orWhereIn('driver_id', User::query()->select('id')->whereRaw('lower(name) like ?', [SearchText::like($q)]));
                })
                ->latest('id')->limit($limit + 1)->get();
            $groups[] = $this->group('rides', 'Rides', $rides, $limit, fn (Ride $r) => $this->row(route('ops.rides.show', $r->id), $this->route($r), '#'.$r->id, $this->status($r)));
        }

        if ($this->ops->allowsRoute($user, 'ops.drivers')) {
            $drivers = DriverProfile::withoutGlobalScope('user')->with('user:id,name')
                ->whereIn('user_id', User::query()->select('id')->where(fn ($w) => $w->whereRaw('lower(name) like ?', [SearchText::like($q)])->orWhereRaw('lower(email) like ?', [SearchText::like($q)])))
                ->orderByDesc('id')->limit($limit + 1)->get();
            $groups[] = $this->group('drivers', 'Drivers', $drivers, $limit, fn (DriverProfile $d) => $this->row(route('ops.drivers.show', $d->user_id), $d->user?->name ?? 'Driver', null, Str::headline((string) $d->status)));

            $vehicles = Vehicle::query()->with('holder:id,user_id')
                ->where(function (Builder $w) use ($q) {
                    SearchText::whereLike($w, 'plate_number', $q);
                    SearchText::whereLike($w, 'make', $q);
                    SearchText::whereLike($w, 'model', $q);
                })
                ->whereNotNull('driver_profile_id')->orderBy('plate_number')->limit($limit + 1)->get();
            // A vehicle has no page of its own for operators: the row opens its holder's driver record.
            $groups[] = $this->group('vehicles', 'Vehicles', $vehicles->filter(fn (Vehicle $v) => $v->holder !== null)->values(), $limit, fn (Vehicle $v) => $this->row(route('ops.drivers.show', $v->holder->user_id), trim($v->make.' '.$v->model), $v->plate_number, 'Vehicle'));
        }

        if ($this->ops->allowsRoute($user, 'ops.fleets')) {
            $fleets = Fleet::query()->where(fn (Builder $w) => SearchText::whereLike(SearchText::whereLike($w, 'name', $q), 'trading_name', $q))
                ->orderBy('name')->limit($limit + 1)->get();
            $groups[] = $this->group('fleets', 'Fleets', $fleets, $limit, fn (Fleet $f) => $this->row(route('ops.fleets'), $f->name, null, Str::headline((string) $f->status)));
        }

        return $groups;
    }

    // ---------------------------------------------------------------- business

    private function business(User $user, string $q, int $limit): array
    {
        $member = $this->portal->memberFor($user);

        if ($member === null || ! $this->portal->allows($user, 'member')) {
            return [];
        }

        $trips = self::businessTrips($member)->where(fn (Builder $w) => self::matchBusinessTrip($w, $q, Abilities::allows($member, 'view_all_trips')))
            ->with('ride:id,pickup_address,dropoff_address,status')->latest('booked_at')->limit($limit + 1)->get();

        return [$this->group('trips', 'Account trips', $trips, $limit, fn (BusinessTrip $t) => $this->row(
            route('business.trips', ['q' => '#'.$t->ride_id]),
            $t->ride ? $this->route($t->ride) : 'Trip',
            '#'.$t->ride_id,
            $t->department_name ?: null,
        ))];
    }

    /**
     * The trips this member may see on the account (the portal's own list uses the same rule): the account's trips
     * that were booked, charged or released; a traveller sees only the ones they took or booked.
     *
     * @return Builder<BusinessTrip>
     */
    public static function businessTrips(BusinessMember $member): Builder
    {
        return BusinessTrip::query()
            ->where('business_account_id', $member->business_account_id)
            ->whereNotNull('ride_id')
            ->whereIn('status', [BusinessTrip::BOOKED, BusinessTrip::CHARGED, BusinessTrip::RELEASED])
            ->when(! Abilities::allows($member, 'view_all_trips'), fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('traveller_member_id', $member->id)->orWhere('booker_member_id', $member->id)));
    }

    /** The text clause shared by the header search and the portal's trips filter (`?q=`). */
    public static function matchBusinessTrip(Builder $w, string $q, bool $seesAll): Builder
    {
        $q = SearchText::clean($q);
        $number = SearchText::rideNumber($q);

        if ($number !== null) {
            $w->orWhere('ride_id', $number);
        }

        $w->orWhereIn('ride_id', Ride::query()->select('id')->where(function (Builder $r) use ($q) {
            SearchText::whereLike($r, 'pickup_address', $q);
            SearchText::whereLike($r, 'dropoff_address', $q);
        }));
        SearchText::whereLike($w, 'department_name', $q);
        SearchText::whereLike($w, 'cost_centre', $q);
        SearchText::whereLike($w, 'purpose', $q);

        if ($seesAll) {
            // A guest rider's name is on the account's own record; only the people who see every trip match on it.
            SearchText::whereLike($w, 'rider_name', $q);
        }

        return $w;
    }

    // ---------------------------------------------------------------- shared

    private function rideMatch(Builder $w, string $q): Builder
    {
        $number = SearchText::rideNumber($q);

        if ($number !== null) {
            $w->orWhere('id', $number);
        }

        SearchText::whereLike($w, 'pickup_address', $q);
        SearchText::whereLike($w, 'dropoff_address', $q);

        return $w;
    }

    private function route(Ride $ride): string
    {
        return Str::limit((string) $ride->pickup_address, 44).' to '.Str::limit((string) $ride->dropoff_address, 44);
    }

    private function status(Ride $ride): ?string
    {
        try {
            return RideStatus::fromStored((string) $ride->status)->label();
        } catch (\ValueError) {
            return null;
        }
    }

    /** @return array{href: string, label: string, code: ?string, tag: ?string} */
    private function row(string $href, string $label, ?string $code = null, ?string $tag = null): array
    {
        return ['href' => $href, 'label' => $label, 'code' => $code, 'tag' => $tag];
    }

    /**
     * @param  iterable<int, mixed>  $models  fetched with limit + 1, so "more" is known without a count query
     * @return array{key: string, head: string, rows: list<array{href: string, label: string, code: ?string, tag: ?string}>, more: bool}
     */
    private function group(string $key, string $head, iterable $models, int $limit, callable $row): array
    {
        $models = collect($models)->values();

        return [
            'key' => $key,
            'head' => $head,
            'rows' => $models->take($limit)->map($row)->all(),
            'more' => $models->count() > $limit,
        ];
    }
}
