<?php

namespace Tests\Concerns;

use App\Domain\Geo\Geometry\Haversine;
use App\Domain\Geo\Geometry\Polyline;
use App\Domain\Geo\LatLng;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * The world every acceptance journey runs in (docs/R46_INTEGRATION.md section 5). Everything inside Dot.Ehail is
 * real: HTTP API, every domain, the database, the queue (sync), the scheduler commands. Only the edges are fake:
 *
 *  - routing provider: an OSRM server (Http::fake) that answers every /route request with a realistic road route
 *    on a city grid (north-south first, then east-west), sampled every 20 m, at 30 km/h, encoded polyline5;
 *  - geocoder: the public Nominatim search for a few named places (its documented jsonv2 shape);
 *  - Paystack: the documented API shapes from MoneyFixtures (initialize, verify, charge_authorization, refund,
 *    transfers) and signed webhooks;
 *  - mail: Mail::fake with an SMTP transport configured, so the safety domain reports "sent" honestly.
 *
 * The geography is Cape Town's City Bowl inside the approximate reference service area: pickup on a street
 * 3 km south and 2 km west of the destination.
 */
trait AcceptanceWorld
{
    use CreatesSafetyFixtures;
    use MoneyFixtures;

    protected const PICKUP = [-33.9400000, 18.4100000];

    protected const DESTINATION = [-33.9130510, 18.4316680];

    /** 2 km further east along the destination's street (the passenger's detour). */
    protected const FURTHER_EAST = [-33.9130510, 18.4533360];

    /** 1 km south of the pickup, on the pickup's street. */
    protected const DRIVER_START = [-33.9490000, 18.4100000];

    protected const CITY_SPEED_MS = 8.33;

    protected bool $routingDown = false;

    protected int $routeRequests = 0;

    /** @var array<string, array{0: float, 1: float, 2: string}> query => [lat, lng, display name] */
    protected array $gazetteer = [
        'Kloof Street' => [self::PICKUP[0], self::PICKUP[1], 'Kloof Street, Gardens, Cape Town, 8001, South Africa'],
        'Bree Street' => [self::DESTINATION[0], self::DESTINATION[1], 'Bree Street, Cape Town City Centre, Cape Town, 8001, South Africa'],
        'Harbour Road' => [self::FURTHER_EAST[0], self::FURTHER_EAST[1], 'Harbour Road, Foreshore, Cape Town, 8001, South Africa'],
    ];

    protected function setUpAcceptanceWorld(): void
    {
        config([
            'ehail-geo.routing.driver' => 'osrm',
            'ehail-geo.providers.osrm.url' => 'https://osrm.acceptance.test',
            'ehail-geo.geocoding.driver' => 'nominatim',
            'mail.default' => 'smtp',
            'money.commission.percent' => 20,
            'ehail_dispatch.pin.required' => true,
        ]);

        Mail::fake();
        $this->enablePaystack();
        $this->fakeMoneyPaystack();
        Http::fake([
            'osrm.acceptance.test/*' => fn (Request $request) => $this->osrmAnswer($request),
            'nominatim.openstreetmap.org/*' => fn (Request $request) => $this->nominatimAnswer($request),
        ]);

        $this->artisan('ehail:geo-reference-areas')->assertSuccessful();
        $this->artisan('ehail:pricing:install-example-tariff')->assertSuccessful();
    }

    // ---------------------------------------------------------------- fake edges

    private function osrmAnswer(Request $request)
    {
        $this->routeRequests++;

        if ($this->routingDown) {
            return Http::response(['code' => 'InvalidUrl', 'message' => 'Service unavailable'], 503);
        }

        preg_match('#/route/v1/driving/([-0-9.]+),([-0-9.]+);([-0-9.]+),([-0-9.]+)#', $request->url(), $m);
        $points = $this->roadPath(new LatLng((float) $m[2], (float) $m[1]), new LatLng((float) $m[4], (float) $m[3]));
        $distance = Haversine::pathMetres($points);

        return Http::response(['code' => 'Ok', 'waypoints' => [], 'routes' => [[
            'distance' => round($distance, 1),
            'duration' => round($distance / self::CITY_SPEED_MS, 1),
            'weight' => round($distance / self::CITY_SPEED_MS, 1),
            'weight_name' => 'routability',
            'geometry' => Polyline::encode($points),
            'legs' => [],
        ]]]);
    }

    private function nominatimAnswer(Request $request)
    {
        $query = (string) ($request->data()['q'] ?? '');

        foreach ($this->gazetteer as $name => [$lat, $lng, $display]) {
            if (stripos($query, $name) !== false) {
                return Http::response([[
                    'place_id' => crc32($name), 'licence' => 'Data © OpenStreetMap contributors, ODbL 1.0.', 'osm_type' => 'way', 'osm_id' => crc32($display),
                    'lat' => (string) $lat, 'lon' => (string) $lng, 'category' => 'highway', 'type' => 'residential', 'place_rank' => 26,
                    'importance' => 0.1, 'addresstype' => 'road', 'name' => $name, 'display_name' => $display,
                    'boundingbox' => [(string) ($lat - 0.001), (string) ($lat + 0.001), (string) ($lng - 0.001), (string) ($lng + 0.001)],
                ]]);
            }
        }

        return Http::response([]);
    }

    /**
     * A city-grid road route: along the start's north-south street, then along the end's east-west street.
     *
     * @return list<LatLng>
     */
    protected function roadPath(LatLng $from, LatLng $to): array
    {
        $corner = new LatLng($to->lat, $from->lng);
        $points = [$from];

        foreach ([[$from, $corner], [$corner, $to]] as [$a, $b]) {
            $steps = max(1, (int) ceil(Haversine::metres($a, $b) / 20));

            for ($i = 1; $i <= $steps; $i++) {
                $points[] = new LatLng($a->lat + ($b->lat - $a->lat) * $i / $steps, $a->lng + ($b->lng - $a->lng) * $i / $steps);
            }
        }

        return $points;
    }

    // ---------------------------------------------------------------- people

    /**
     * Several people use the app in one test. Jetstream's AuthenticateSession pins the session to one password
     * hash, so switching person starts from a clean session (as a different phone would).
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        if ($this->app->bound('session.store')) {
            $this->app['session.store']->forget(['password_hash_web', 'password_hash_sanctum']);
        }

        return $this->be($user, $guard);
    }

    protected function registerUser(string $name, string $email): User
    {
        auth()->guard('web')->logout();

        $this->post('/register', [
            'name' => $name, 'email' => $email, 'password' => 'a-long-test-password-2026', 'password_confirmation' => 'a-long-test-password-2026', 'terms' => true,
        ])->assertRedirect();

        $user = User::where('email', $email)->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    protected function operator(): User
    {
        return User::where('is_platform_operator', true)->first() ?? $this->makeOperator();
    }

    protected function approvedFleet(): Fleet
    {
        return Fleet::query()->where('status', Fleet::STATUS_APPROVED)->first() ?? Fleet::create([
            'name' => 'Atlantic Seaboard Cabs', 'owner_user_id' => User::factory()->withPersonalTeam()->create()->id, 'status' => Fleet::STATUS_APPROVED,
        ]);
    }

    /**
     * A driver set up without the HTTP onboarding (the driver journey covers that): approved profile in an
     * approved fleet, a 2022 Toyota Corolla (standard), every mandatory document approved.
     */
    protected function verifiedDriver(string $name = 'Lindiwe Dube'): User
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => $name]);
        $profile = DriverProfile::factory()->for($user)->create(['status' => 'approved', 'is_online' => false, 'fleet_id' => $this->approvedFleet()->id]);
        $vehicle = Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true, 'type' => 'standard', 'year' => 2022]);
        $this->approveAllDocuments($profile, $vehicle);

        return $user;
    }

    /**
     * Online through the API, with a first position posted by the driver's phone.
     *
     * @param  array{0: float, 1: float}  $at
     */
    protected function goOnlineAt(User $driver, array $at = self::DRIVER_START): void
    {
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.online'))->assertOk()->assertJsonPath('availability.state', 'online');
        $this->postPosition($driver, $at[0], $at[1]);
    }

    protected function postPosition(User $driver, float $lat, float $lng, float $speed = 0.0, ?float $heading = null): TestResponse
    {
        return $this->actingAs($driver)->postJson(route('geo.driver.location'), ['samples' => [[
            'lat' => round($lat, 7), 'lng' => round($lng, 7), 'accuracy' => 8, 'speed' => $speed, 'heading' => $heading,
            'recorded_at' => now()->getTimestampMs(),
        ]]])->assertOk();
    }

    /**
     * Drive a road path at a steady speed, the phone posting a fix every $every seconds (time really passes).
     *
     * @param  array{0: float, 1: float}  $from
     * @param  array{0: float, 1: float}  $to
     * @return int seconds driven
     */
    protected function drive(User $driver, array $from, array $to, float $speed = self::CITY_SPEED_MS, int $every = 15): int
    {
        $path = $this->roadPath(new LatLng($from[0], $from[1]), new LatLng($to[0], $to[1]));
        $total = Haversine::pathMetres($path);
        $seconds = 0;

        for ($travelled = $speed * $every; ; $travelled += $speed * $every) {
            $this->travel($every)->seconds();
            $seconds += $every;
            $point = $this->pointAlong($path, min($travelled, $total));
            $this->postPosition($driver, $point->lat, $point->lng, $speed);

            if ($travelled >= $total) {
                return $seconds;
            }
        }
    }

    /**
     * @param  list<LatLng>  $path
     */
    private function pointAlong(array $path, float $metres): LatLng
    {
        for ($i = 1, $n = count($path); $i < $n; $i++) {
            $segment = Haversine::metres($path[$i - 1], $path[$i]);

            if ($metres <= $segment) {
                $f = $segment > 0 ? $metres / $segment : 0;

                return new LatLng($path[$i - 1]->lat + ($path[$i]->lat - $path[$i - 1]->lat) * $f, $path[$i - 1]->lng + ($path[$i]->lng - $path[$i - 1]->lng) * $f);
            }

            $metres -= $segment;
        }

        return $path[count($path) - 1];
    }

    // ---------------------------------------------------------------- passenger steps

    /**
     * @param  array{0: float, 1: float}  $to
     * @return array<string, mixed> the quote for one category
     */
    protected function quoteFor(User $passenger, string $category = 'standard', array $from = self::PICKUP, array $to = self::DESTINATION): array
    {
        return $this->actingAs($passenger)->postJson(route('pricing.quotes.store'), [
            'pickup_lat' => $from[0], 'pickup_lng' => $from[1], 'dropoff_lat' => $to[0], 'dropoff_lng' => $to[1], 'category' => $category,
        ])->assertOk()->assertJsonPath('available', true)->assertJsonPath('kind', 'quote')->json();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function requestRide(User $passenger, string $quoteId, array $extra = [], ?string $key = null): TestResponse
    {
        return $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $quoteId, 'pickup_address' => 'Kloof Street, Gardens', 'dropoff_address' => 'Bree Street, City Centre'] + $extra,
            $key === null ? [] : ['Idempotency-Key' => $key]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function driverFeed(User $driver): array
    {
        return $this->actingAs($driver)->getJson(route('ehail.dispatch.driver.feed'))->assertOk()->json();
    }

    /**
     * @return array<string, mixed>
     */
    protected function passengerFeed(User $passenger, int $rideId): array
    {
        return $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', $rideId))->assertOk()->json();
    }

    protected function stage(User $driver, int $rideId, string $stage, ?string $pin = null): TestResponse
    {
        return $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$rideId, $stage]), $pin === null ? [] : ['pin' => $pin]);
    }

    /**
     * Accept the driver's pending offer, drive to the pickup and arrive. Returns the passenger's PIN.
     */
    protected function acceptAndArrive(User $driver, User $passenger, int $rideId, array $driverAt = self::DRIVER_START): string
    {
        $offer = $this->driverFeed($driver)['offer'];
        $this->assertSame($rideId, $offer['ride_id']);
        $this->actingAs($driver)->postJson(route('ehail.dispatch.offers.accept', $offer['id']))->assertOk();
        $this->stage($driver, $rideId, 'arriving')->assertOk();
        $this->drive($driver, $driverAt, self::PICKUP);
        $this->stage($driver, $rideId, 'arrived')->assertOk();

        return (string) $this->passengerFeed($passenger, $rideId)['ride']['pin'];
    }

    /**
     * A passenger with a saved card books the standard category; the online driver accepts, drives to the
     * pickup and starts the trip with the passenger's PIN. Returns the ride id.
     *
     * @param  array{0: float, 1: float}  $to
     */
    protected function tripUnderWay(User $passenger, User $driver, array $to = self::DESTINATION): int
    {
        $quote = $this->quoteFor($passenger, 'standard', self::PICKUP, $to);
        $rideId = $this->requestRide($passenger, $quote['quote_id'])->assertCreated()->json('ride.id');
        $pin = $this->acceptAndArrive($driver, $passenger, $rideId);
        $this->stage($driver, $rideId, 'start', $pin)->assertOk()->assertJsonPath('ride_status', 'in_progress');

        return $rideId;
    }

    // ---------------------------------------------------------------- money checks

    protected function ledgerBalances(): bool
    {
        $row = DB::table('ledger_entries')->selectRaw('COALESCE(SUM(debit_minor),0) d, COALESCE(SUM(credit_minor),0) c')->first();
        $perJournal = DB::table('ledger_entries')->selectRaw('journal_id, SUM(debit_minor) - SUM(credit_minor) as diff')->groupBy('journal_id')->get()
            ->every(fn ($r) => (int) $r->diff === 0);

        return (int) $row->d === (int) $row->c && $perJournal;
    }

    protected function ledgerBalance(string $account): int
    {
        return app(Ledger::class)->balance($account, 'ZAR');
    }

    protected function platformCommission(): int
    {
        return $this->ledgerBalance(Account::PLATFORM_REVENUE_COMMISSION);
    }
}
