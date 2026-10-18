<?php

namespace App\Services\Analytics;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RideRating;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The numbers Dot.Ehail publishes to Dot.Analytics: pure read-only aggregate queries. Only the
 * keys of Dot.Analytics' metrics contract for the `ehail` platform are ever produced:
 * rides_requested_7d, rides_completed_7d, rides_expired_7d, drivers_online, avg_rating. No name,
 * address, plate, fare or row ever leaves here.
 *
 * Who owns the data. Drivers belong to a FLEET (driver_profiles.fleet_id) and a fleet to its
 * OWNER (a user), so two kinds of snapshot exist:
 *
 *  - FLEET snapshot, one per company owning an approved fleet. The company is the InfoDot team
 *    (infodot_team_uuid) the fleet owner currently belongs to (see CompanyDirectory), the same
 *    "owned approved fleets" scope the app's own dashboard gives a fleet owner. It carries only
 *    what can truthfully be attributed to a fleet: rides_completed_7d (completed rides its drivers
 *    drove), drivers_online and avg_rating (its drivers). rides_requested_7d and rides_expired_7d
 *    are deliberately OMITTED: a requested ride has no driver, and an expired one has had its
 *    driver cleared, so neither belongs to any fleet.
 *  - PLATFORM snapshot, only for the operating company named by ANALYTICS_COMPANY_UUID (blank =
 *    none). All five keys, platform-wide, as the app's operator dashboard shows them. If that
 *    company is also a fleet owner, this snapshot is the one published for it.
 *
 * A fleet owner with no linked team, or an unapproved (pending) fleet, publishes nothing.
 *
 * Windows are UTC. taken_at is the start of a UTC day and every "7d" metric covers the seven
 * whole UTC days before it, [taken_at - 7d, taken_at). Bounds are converted to the app timezone
 * before they reach a query because timestamps are stored in it. drivers_online and avg_rating
 * are point-in-time and all-time respectively, read when the snapshot is collected.
 *
 *   rides_requested_7d  rides created in the window (created_at: requested_at is reset when a
 *                       driver hands a ride back, created_at never is), whatever happened to them
 *   rides_completed_7d  status 'completed' with completed_at in the window
 *   rides_expired_7d    status 'expired' with expired_at in the window
 *   drivers_online      approved drivers marked online right now (the ones who may take a ride)
 *   avg_rating          mean of the passengers' 1-5 ratings of drivers, all time, rounded to 2
 *                       decimals; left out when there are no ratings (never 0)
 */
class MetricsCollector
{
    /** @var list<string> */
    public const KEYS = ['rides_requested_7d', 'rides_completed_7d', 'rides_expired_7d', 'drivers_online', 'avg_rating'];

    /** @var list<string> What a fleet owner's snapshot may carry. */
    public const FLEET_KEYS = ['rides_completed_7d', 'drivers_online', 'avg_rating'];

    public function __construct(private readonly CompanyDirectory $directory) {}

    /**
     * @return array<string, array<string, int|float>> company uuid => metrics
     */
    public function snapshots(CarbonInterface $takenAt, ?string $onlyCompany = null): array
    {
        $ownerIds = Fleet::approved()->distinct()->pluck('owner_user_id')->all();
        $out = [];

        foreach ($this->directory->group($ownerIds) as $uuid => $owners) {
            $fleetIds = Fleet::approved()->whereIn('owner_user_id', $owners)->pluck('id')->all();
            $out[$uuid] = $this->collectForFleets($fleetIds, $takenAt);
        }

        $operator = $this->operatorCompany();

        if ($operator !== null) {
            $out[$operator] = $this->collectPlatform($takenAt);
        }

        $out = array_filter($out, fn (array $metrics) => $metrics !== []);

        if ($onlyCompany !== null) {
            $out = array_filter($out, fn (string $uuid) => strtolower($uuid) === strtolower($onlyCompany), ARRAY_FILTER_USE_KEY);
        }

        ksort($out);

        return $out;
    }

    /** The configured operating company, only if it is a well-formed uuid. */
    public function operatorCompany(): ?string
    {
        $uuid = trim((string) config('analytics.company_uuid'));

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1 ? strtolower($uuid) : null;
    }

    /**
     * What a company owning these fleets can truthfully say about them.
     *
     * @param  array<int, int>  $fleetIds  the fleets of ONE company (empty means no fleets, never "all")
     * @return array<string, int|float>
     */
    public function collectForFleets(array $fleetIds, CarbonInterface $takenAt): array
    {
        if ($fleetIds === []) {
            return [];
        }

        [$from, $to] = $this->window($takenAt);
        $drivers = fn () => DriverProfile::withoutGlobalScope('user')->whereIn('fleet_id', $fleetIds);

        $metrics = [
            'rides_completed_7d' => $this->completed($from, $to)->whereIn('driver_id', $drivers()->select('user_id'))->count(),
            'drivers_online' => $this->online($drivers())->count(),
        ];

        $this->addRating($metrics, RideRating::query()->whereIn('rated_user_id', $drivers()->select('user_id')));

        return $metrics;
    }

    /**
     * Platform-wide: for the operating company only.
     *
     * @return array<string, int|float>
     */
    public function collectPlatform(CarbonInterface $takenAt): array
    {
        [$from, $to] = $this->window($takenAt);

        $metrics = [
            'rides_requested_7d' => Ride::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            'rides_completed_7d' => $this->completed($from, $to)->count(),
            'rides_expired_7d' => Ride::query()->where('status', 'expired')->where('expired_at', '>=', $from)->where('expired_at', '<', $to)->count(),
            'drivers_online' => $this->online(DriverProfile::withoutGlobalScope('user'))->count(),
        ];

        $this->addRating($metrics, RideRating::query());

        return $metrics;
    }

    /** @return Builder<Ride> */
    private function completed(Carbon $from, Carbon $to): Builder
    {
        return Ride::query()->where('status', 'completed')->where('completed_at', '>=', $from)->where('completed_at', '<', $to);
    }

    /**
     * @param  Builder<DriverProfile>  $profiles
     * @return Builder<DriverProfile>
     */
    private function online(Builder $profiles): Builder
    {
        return $profiles->where('status', 'approved')->where('is_online', true);
    }

    /**
     * @param  array<string, int|float>  $metrics
     * @param  Builder<RideRating>  $ratings
     */
    private function addRating(array &$metrics, Builder $ratings): void
    {
        $average = $ratings->where('rated_role', RideRating::ROLE_DRIVER)->avg('rating');

        if ($average !== null) {
            $metrics['avg_rating'] = round((float) $average, 2);
        }
    }

    /**
     * The seven whole UTC days before taken_at, as moments in the app timezone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(CarbonInterface $takenAt): array
    {
        $to = Carbon::instance($takenAt)->utc()->startOfDay()->timezone((string) config('app.timezone'));

        return [$to->copy()->subDays(7), $to];
    }
}
