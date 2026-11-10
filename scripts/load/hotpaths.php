<?php

/*
 * Server hot paths at realistic volume (DEVELOPMENT ONLY; docs/R46_PERF.md section 5).
 *
 *   php scripts/load/hotpaths.php seed      fresh schema + 2,000 drivers (600 online), 3,000 passengers, 10,000 rides
 *                                           over 60 days (with timelines and offers), 50,000 trip points, ledger rows
 *   php scripts/load/hotpaths.php measure   statements and median time per hot path, through the full HTTP stack
 *                                           (database sessions, rate limiter, middleware) where it is an endpoint
 *
 * Same guards as world.php (APP_ENV local/testing, a *perf* SQLite file or an ehail_perf_* PostgreSQL database).
 * Needs SESSION_DRIVER=database CACHE_STORE=database and, for quotes, the OSRM stub (EHAIL_ROUTING_DRIVER=osrm).
 */

use App\Domain\Dispatch\Dispatcher;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Locations\LocationStore;
use App\Domain\Health\HealthReport;
use App\Domain\Ops\Insights\InsightsQuery;
use App\Domain\Ops\Live\LiveBoard;
use App\Domain\Perf\QueryProbe;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$connection = config('database.default');
$database = (string) config("database.connections.{$connection}.database");

if (! app()->environment('local', 'testing') || ! (($connection === 'sqlite' && str_contains(basename($database), 'perf')) || ($connection === 'pgsql' && str_starts_with($database, 'ehail_perf_')))) {
    fwrite(STDERR, "Refusing: development perf databases only (see the header).\n");
    exit(2);
}

const CENTRE = [-33.9249, 18.4241];

function point(float $radiusM): array
{
    $a = mt_rand() / mt_getrandmax() * 2 * M_PI;
    $r = sqrt(mt_rand() / mt_getrandmax()) * $radiusM;

    return [CENTRE[0] + $r * cos($a) / 111320, CENTRE[1] + $r * sin($a) / (111320 * cos(deg2rad(CENTRE[0])))];
}

function insertChunks(string $table, array $rows): void
{
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table($table)->insert($chunk);
    }
}

$command = $argv[1] ?? 'help';

if ($command === 'seed') {
    mt_srand(4646);
    $t = microtime(true);
    Artisan::call('migrate:fresh', ['--force' => true]);
    Artisan::call('ehail:geo-reference-areas');
    Artisan::call('ehail:pricing:install-example-tariff');

    $owner = User::factory()->withPersonalTeam()->create(['email' => 'hot-owner@example.test']);
    $fleet = Fleet::create(['name' => 'Hot path fleet', 'owner_user_id' => $owner->id, 'status' => Fleet::STATUS_APPROVED]);
    $password = bcrypt('not-used');
    $now = now();

    // 3,000 passengers and 2,000 drivers (bulk: these are rows for volume, not journeys).
    foreach ([['hot-passenger', 3000], ['hot-driver', 2000]] as [$prefix, $n]) {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ['name' => ucfirst(str_replace('-', ' ', $prefix)).' '.$i, 'email' => "{$prefix}-{$i}@example.test", 'password' => $password, 'created_at' => $now, 'updated_at' => $now];
        }
        insertChunks('users', $rows);
    }

    $passengerIds = DB::table('users')->where('email', 'like', 'hot-passenger-%')->orderBy('id')->pluck('id')->all();
    $driverIds = DB::table('users')->where('email', 'like', 'hot-driver-%')->orderBy('id')->pluck('id')->all();
    $licence = encrypt('LIC-HOT-0000', false);
    $idNumber = encrypt('0000000000000', false);
    $profiles = [];
    foreach ($driverIds as $id) {
        $profiles[] = ['user_id' => $id, 'license_number' => $licence, 'id_number' => $idNumber, 'fleet_id' => $fleet->id, 'status' => 'approved', 'is_online' => false, 'rating' => 4.7, 'created_at' => $now, 'updated_at' => $now];
    }
    insertChunks('driver_profiles', $profiles);
    $profileIds = DB::table('driver_profiles')->orderBy('id')->pluck('id', 'user_id')->all();
    $vehicles = [];
    foreach ($driverIds as $i => $id) {
        $vehicles[] = ['driver_profile_id' => $profileIds[$id], 'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2022, 'color' => 'White', 'plate_number' => sprintf('CA %06d', $i), 'type' => 'standard', 'seats' => 4, 'doors' => 4, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
    }
    insertChunks('vehicles', $vehicles);
    $vehicleIds = DB::table('vehicles')->orderBy('id')->pluck('id', 'driver_profile_id')->all();

    // 600 online with fresh positions (the rest have an offline availability row).
    $availability = [];
    $locations = [];
    foreach ($driverIds as $i => $id) {
        $online = $i < 600;
        [$lat, $lng] = point(8000);
        $availability[] = ['user_id' => $id, 'vehicle_id' => $vehicleIds[$profileIds[$id]], 'state' => $online ? 'online' : 'offline', 'since' => $now, 'online_since' => $online ? $now : null,
            'last_heartbeat_at' => $online ? $now : null, 'last_lat' => $online ? $lat : null, 'last_lng' => $online ? $lng : null, 'last_location_at' => $online ? $now : null,
            'version' => 1, 'location_version' => 1, 'created_at' => $now, 'updated_at' => $now];
        if ($online) {
            $locations[] = ['driver_id' => $id, 'lat' => $lat, 'lng' => $lng, 'accuracy_m' => 8, 'recorded_at' => $now, 'received_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        }
    }
    insertChunks('driver_availability', $availability);
    insertChunks('driver_locations', $locations);

    // 10,000 rides over 60 days: 85 % completed, the rest cancelled or expired; 8 timeline events and ~1.2 offers each.
    $rides = [];
    for ($i = 0; $i < 10000; $i++) {
        $at = CarbonImmutable::now()->subMinutes(mt_rand(30, 60 * 24 * 60));
        [$plat, $plng] = point(7000);
        [$dlat, $dlng] = point(9000);
        $status = mt_rand(1, 100) <= 85 ? 'completed' : (mt_rand(0, 1) ? 'cancelled_by_passenger' : 'expired');
        $driver = $status === 'expired' ? null : $driverIds[array_rand($driverIds)];
        $rides[] = ['passenger_id' => $passengerIds[array_rand($passengerIds)], 'driver_id' => $driver, 'status' => $status,
            'pickup_address' => 'Hot pickup '.$i, 'dropoff_address' => 'Hot drop-off '.$i, 'pickup_lat' => $plat, 'pickup_lng' => $plng, 'dropoff_lat' => $dlat, 'dropoff_lng' => $dlng,
            'vehicle_type' => 'standard', 'category_code' => 'standard', 'currency' => 'ZAR', 'quoted_total_minor' => mt_rand(4500, 25000), 'final_fare_minor' => $status === 'completed' ? mt_rand(4500, 25000) : null,
            'route_distance_m' => mt_rand(2000, 20000), 'route_duration_s' => mt_rand(300, 2400),
            'requested_at' => $at, 'accepted_at' => $driver ? $at->addMinutes(2) : null, 'started_at' => $status === 'completed' ? $at->addMinutes(8) : null,
            'completed_at' => $status === 'completed' ? $at->addMinutes(30) : null, 'cancelled_at' => str_starts_with($status, 'cancelled') ? $at->addMinutes(3) : null,
            'expired_at' => $status === 'expired' ? $at->addMinutes(10) : null, 'version' => 1, 'created_at' => $at, 'updated_at' => $at->addMinutes(30)];
    }
    insertChunks('rides', $rides);

    $events = [];
    $offers = [];
    foreach (DB::table('rides')->orderBy('id')->get(['id', 'driver_id', 'status', 'requested_at']) as $ride) {
        $chain = $ride->status === 'completed' ? ['requested', 'searching', 'driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress', 'completed']
            : ($ride->status === 'expired' ? ['requested', 'searching', 'expired'] : ['requested', 'searching', $ride->status]);
        $from = null;
        foreach ($chain as $seq => $to) {
            $events[] = ['ride_id' => $ride->id, 'sequence' => $seq + 1, 'type' => 'transition', 'from_status' => $from, 'to_status' => $to, 'actor_type' => 'system', 'occurred_at' => $ride->requested_at];
            $from = $to;
        }
        if ($ride->driver_id) {
            $offers[] = ['ride_id' => $ride->id, 'driver_id' => $ride->driver_id, 'status' => 'accepted', 'sent_at' => $ride->requested_at, 'expires_at' => $ride->requested_at, 'version' => 1, 'created_at' => $ride->requested_at, 'updated_at' => $ride->requested_at];
        }
        if (count($events) > 4000) {
            insertChunks('ride_events', $events);
            insertChunks('ride_offers', $offers);
            $events = $offers = [];
        }
    }
    insertChunks('ride_events', $events);
    insertChunks('ride_offers', $offers);

    // 50,000 trip points on 1,000 completed rides, half older than the 90-day retention window (back-dated rides).
    $old = DB::table('rides')->where('status', 'completed')->orderBy('id')->limit(1000)->get(['id', 'driver_id']);
    $points = [];
    foreach ($old as $n => $ride) {
        $base = $n < 500 ? CarbonImmutable::now()->subDays(120) : CarbonImmutable::now()->subDays(10);
        [$lat, $lng] = point(7000);
        for ($p = 0; $p < 50; $p++) {
            $points[] = ['ride_id' => $ride->id, 'driver_id' => $ride->driver_id, 'lat' => $lat + $p * 0.0002, 'lng' => $lng, 'accuracy_m' => 8, 'speed_mps' => 8.3, 'recorded_at' => $base->addSeconds($p * 4), 'received_at' => $base->addSeconds($p * 4)];
        }
        if (count($points) >= 5000) {
            insertChunks('trip_points', $points);
            $points = [];
        }
    }
    insertChunks('trip_points', $points);
    DB::table('rides')->whereIn('id', $old->take(500)->pluck('id'))->update(['completed_at' => CarbonImmutable::now()->subDays(120), 'created_at' => CarbonImmutable::now()->subDays(120)]);

    // Ledger: a trip revenue journal (4 entries) for each completed ride.
    $journals = [];
    $completed = DB::table('rides')->where('status', 'completed')->orderBy('id')->get(['id', 'final_fare_minor', 'completed_at', 'driver_id']);
    foreach ($completed as $ride) {
        $journals[] = ['kind' => 'trip_revenue', 'idempotency_key' => 'hot:trip:'.$ride->id, 'currency' => 'ZAR', 'ride_id' => $ride->id, 'reason' => 'Trip revenue', 'posted_at' => $ride->completed_at, 'created_at' => $ride->completed_at];
    }
    insertChunks('ledger_journals', $journals);
    $entries = [];
    foreach (DB::table('ledger_journals')->orderBy('id')->get(['id', 'ride_id']) as $j) {
        $fare = (int) $completed->firstWhere('id', $j->ride_id)?->final_fare_minor;
        $commission = intdiv($fare, 5);
        foreach ([['passenger_receivable', $fare, 0], ['platform_commission', 0, $commission], ['driver_payable', 0, $fare - $commission]] as [$type, $d, $c]) {
            $entries[] = ['journal_id' => $j->id, 'account' => $type, 'account_type' => $type, 'debit_minor' => $d, 'credit_minor' => $c, 'currency' => 'ZAR', 'created_at' => $now];
        }
        if (count($entries) >= 6000) {
            insertChunks('ledger_entries', $entries);
            $entries = [];
        }
    }
    insertChunks('ledger_entries', $entries);

    // Live work for the hot paths: 20 searching rides and 30 trips in progress.
    $dispatcher = app(Dispatcher::class);
    $online = DB::table('driver_availability')->where('state', 'online')->orderBy('user_id')->limit(30)->pluck('user_id')->all();
    foreach ($online as $k => $driverId) {
        [$plat, $plng] = point(5000);
        $ride = Ride::create(['passenger_id' => $passengerIds[$k], 'driver_id' => $driverId, 'active_driver_id' => $driverId, 'active_passenger_id' => $passengerIds[$k], 'status' => 'in_progress',
            'pickup_address' => 'Live pickup', 'dropoff_address' => 'Live drop-off', 'pickup_lat' => $plat, 'pickup_lng' => $plng, 'dropoff_lat' => CENTRE[0], 'dropoff_lng' => CENTRE[1],
            'vehicle_type' => 'standard', 'category_code' => 'standard', 'currency' => 'ZAR', 'quoted_total_minor' => 9000, 'requested_at' => now()->subMinutes(20), 'accepted_at' => now()->subMinutes(18), 'started_at' => now()->subMinutes(5)]);
        DB::table('driver_availability')->where('user_id', $driverId)->update(['state' => 'on_trip', 'current_ride_id' => $ride->id]);
    }
    for ($k = 100; $k < 120; $k++) {
        [$plat, $plng] = point(5000);
        Ride::create(['passenger_id' => $passengerIds[$k], 'active_passenger_id' => $passengerIds[$k], 'status' => 'searching', 'pickup_address' => 'Waiting pickup', 'dropoff_address' => 'Waiting drop-off',
            'pickup_lat' => $plat, 'pickup_lng' => $plng, 'dropoff_lat' => CENTRE[0], 'dropoff_lng' => CENTRE[1], 'vehicle_type' => 'standard', 'category_code' => 'standard', 'currency' => 'ZAR',
            'quoted_total_minor' => 8000, 'requested_at' => now()->subSeconds(30), 'search_started_at' => now()->subSeconds(30)]);
    }

    $counts = [];
    foreach (['users', 'rides', 'ride_events', 'ride_offers', 'trip_points', 'driver_availability', 'driver_locations', 'ledger_entries'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }
    printf("Seeded in %.1f s: %s\n", microtime(true) - $t, json_encode($counts));
    exit(0);
}

if ($command !== 'measure') {
    echo "usage: php scripts/load/hotpaths.php seed | measure\n";
    exit(0);
}

QueryProbe::listen();
$http = app(HttpKernel::class);

function session(User $user): string
{
    $id = Str::random(40);
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'hotpaths', 'last_activity' => now()->getTimestamp(),
        'payload' => base64_encode(json_encode(['_token' => 'hot', 'login_web_'.sha1(SessionGuard::class) => $user->id, 'password_hash_web' => null, '_flash' => ['old' => [], 'new' => []]]))]);
    $name = config('session.cookie');

    return app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$id, false);
}

/**
 * @return array{queries: float, writes: float, ms: float, status: int}
 */
function measure(callable $fn, int $runs = 15): array
{
    $samples = [];
    $fn(); // warm (opcode, first-touch caches)

    for ($i = 0; $i < $runs; $i++) {
        QueryProbe::$queries = QueryProbe::$writes = 0;
        $t = hrtime(true);
        $status = $fn();
        $samples[] = [QueryProbe::$queries, QueryProbe::$writes, (hrtime(true) - $t) / 1e6, $status];
    }

    usort($samples, fn ($a, $b) => $a[2] <=> $b[2]);
    $mid = $samples[intdiv(count($samples), 2)];

    return ['queries' => $mid[0], 'writes' => $mid[1], 'ms' => round($mid[2], 1), 'status' => (int) $mid[3]];
}

function call(string $method, string $uri, ?User $as, array $body = [], array $headers = []): int
{
    global $http, $cookies;
    $server = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_X_CSRF_TOKEN' => 'hot', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.'.mt_rand(2, 250)];
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }
    $cookie = $as ? [config('session.cookie') => $cookies[$as->id] ??= session($as)] : [];
    $request = Request::create($uri, $method, [], $cookie, [], $server, $body === [] ? null : json_encode($body));
    $response = $http->handle($request);
    if (getenv('HOT_DEBUG') && $response->getStatusCode() >= 300) {
        fwrite(STDERR, "{$method} {$uri} -> {$response->getStatusCode()} ".substr((string) $response->getContent(), 0, 300).PHP_EOL);
    }
    $http->terminate($request, $response);
    Auth::forgetGuards();
    app()->forgetInstance('request');

    return $response->getStatusCode();
}

$cookies = [];
$operator = User::factory()->withPersonalTeam()->create(['is_platform_operator' => true, 'email' => 'hot-op-'.Str::random(5).'@example.test']);
$idleDriver = User::find(DB::table('driver_availability')->where('state', 'online')->whereNull('current_ride_id')->value('user_id'));
$tripDriver = User::find(DB::table('driver_availability')->where('state', 'on_trip')->value('user_id'));
$tripRide = Ride::where('status', 'in_progress')->first();
$tripPassenger = User::find($tripRide->passenger_id);
$quoter = User::where('email', 'hot-passenger-2999@example.test')->first();
$results = [];

$results['driver sync, idle (no position)'] = measure(fn () => call('POST', '/api/ehail/driver/sync', $idleDriver, ['samples' => []]));
$results['driver sync, on a trip (1 position)'] = measure(function () use ($tripDriver) {
    static $n = 0;
    $n++;

    return call('POST', '/api/ehail/driver/sync', $tripDriver, ['samples' => [['lat' => CENTRE[0] + $n * 0.0003, 'lng' => CENTRE[1], 'accuracy' => 8, 'speed' => 9, 'heading' => 0, 'recorded_at' => now()->addMilliseconds($n * 5)->getTimestampMs()]]]);
});
$results['driver feed (legacy GET)'] = measure(fn () => call('GET', '/api/ehail/driver/feed', $idleDriver));
$results['passenger feed, trip in progress'] = measure(fn () => call('GET', '/api/ehail/rides/'.$tripRide->id.'/feed', $tripPassenger));
$results['quote (one category, routing stub)'] = measure(fn () => call('POST', '/pricing/quotes', $quoter, ['pickup_lat' => -33.93, 'pickup_lng' => 18.42, 'dropoff_lat' => -33.91, 'dropoff_lng' => 18.43, 'category' => 'standard']), 8);
$results['compare (every category, routing stub)'] = measure(fn () => call('POST', '/pricing/quotes', $quoter, ['pickup_lat' => -33.93, 'pickup_lng' => 18.42, 'dropoff_lat' => -33.91, 'dropoff_lng' => 18.43]), 8);
$results['categories available at a point'] = measure(fn () => call('POST', '/pricing/categories/available', $quoter, ['lat' => -33.93, 'lng' => 18.42]), 8);
$results['nearby drivers (4 km, 600 online)'] = measure(function () {
    QueryProbe::listen();
    app(LocationStore::class)->nearbyDrivers(new LatLng(CENTRE[0], CENTRE[1]), 4000, ['max_age_seconds' => 3600]);

    return 200;
});
$results['dispatch tick (20 searching rides)'] = measure(function () {
    // Each run starts from 20 rides with no offer in flight (the previous run's offers are withdrawn).
    DB::table('ride_offers')->where('status', 'pending')->delete();
    DB::table('rides')->where('status', 'searching')->update(['next_dispatch_at' => null, 'dispatch_lease_until' => null, 'pending_offer_expires_at' => null, 'search_round' => 0]);
    QueryProbe::$queries = QueryProbe::$writes = 0;

    return app(Dispatcher::class)->tickDue(20) === 20 ? 200 : 500;
}, 5);
// Ops pages are measured at the domain call the page makes (the operator middleware is not the hot part).
$results['ops live board payload (600 online, 50 live rides)'] = measure(function () use ($operator) {
    return count(app(LiveBoard::class)->payload($operator)['drivers']) > 0 ? 200 : 500;
}, 8);
$results['ops insights (30 days, 10k rides)'] = measure(function () {
    app(InsightsQuery::class)->all(CarbonImmutable::now()->subDays(30)->startOfDay(), CarbonImmutable::now(), 'day');

    return 200;
}, 5);
$results['ops status (health report)'] = measure(function () {
    cache()->forget('ehail:health:ops-report');

    return app(HealthReport::class)->run() ? 200 : 500;
}, 8);

$t = microtime(true);
Artisan::call('ehail:geo-purge');
$results['retention: ehail:geo-purge (25k old points)'] = ['queries' => null, 'writes' => null, 'ms' => round((microtime(true) - $t) * 1000), 'status' => 0, 'output' => trim(Artisan::output())];

printf("%-48s %8s %7s %9s %6s\n", 'hot path', 'queries', 'writes', 'median ms', 'status');
foreach ($results as $name => $r) {
    printf("%-48s %8s %7s %9s %6s\n", $name, $r['queries'] ?? '-', $r['writes'] ?? '-', $r['ms'], $r['status']);
    if (isset($r['output'])) {
        echo '    '.str_replace("\n", "\n    ", $r['output'])."\n";
    }
}
echo json_encode(['connection' => $connection, 'results' => $results]).PHP_EOL;
