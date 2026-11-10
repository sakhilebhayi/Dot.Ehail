<?php

/*
 * Load-test world for docs/R46_PERF.md. DEVELOPMENT ONLY.
 *
 *   php scripts/load/world.php setup <drivers> <passengers> <out.json>   fresh schema + verified online drivers + passengers + sessions
 *   php scripts/load/world.php invariants                                checks the invariants, prints JSON, exit 1 on a breach
 *   php scripts/load/world.php stats                                     counts (rides by status, offers, events, trip points)
 *
 * Guards: refuses unless APP_ENV is local or testing AND the database is either a SQLite file whose name contains
 * "perf" or a PostgreSQL database named ehail_perf_*. It migrates that database from scratch, so it must never point
 * at anything else. Sessions are minted directly in the sessions table (SESSION_DRIVER=database) so 500 drivers do
 * not have to log in through the throttled login form; the cookies only work against this throwaway database.
 */

use App\Domain\Dispatch\AvailabilityService;
use App\Domain\Payments\Models\DriverMoneySetting;
use App\Domain\Perf\SystemInvariants;
use App\Domain\Verification\DocumentRequirements;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(2);
}

if (! app()->environment('local', 'testing')) {
    fail('world.php runs only with APP_ENV=local or testing.');
}

$connection = config('database.default');
$database = (string) config("database.connections.{$connection}.database");

if (! (($connection === 'sqlite' && str_contains(basename($database), 'perf')) || ($connection === 'pgsql' && str_starts_with($database, 'ehail_perf_')))) {
    fail("Refusing: database {$connection}:{$database} is not a perf database (sqlite file named *perf* or pgsql ehail_perf_*).");
}

$command = $argv[1] ?? 'help';

/** Cape Town city bowl and surrounds, inside the approximate reference service area. */
const CENTRE = [-33.9249, 18.4241];

function around(float $radiusM): array
{
    $angle = mt_rand() / mt_getrandmax() * 2 * M_PI;
    $r = sqrt(mt_rand() / mt_getrandmax()) * $radiusM;

    return [CENTRE[0] + ($r * cos($angle)) / 111320, CENTRE[1] + ($r * sin($angle)) / (111320 * cos(deg2rad(CENTRE[0])))];
}

function mintSession(User $user): array
{
    $id = Str::random(40);
    $token = Str::random(40);
    $payload = ['_token' => $token, 'login_web_'.sha1(SessionGuard::class) => $user->id, '_flash' => ['old' => [], 'new' => []]];

    DB::table(config('session.table', 'sessions'))->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'ehail-load/1.0',
        'payload' => base64_encode(json_encode($payload)),
        'last_activity' => now()->getTimestamp(),
    ]);

    $name = config('session.cookie');
    $value = app('encrypter')->encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$id, false);

    return ['cookie' => $name.'='.rawurlencode($value), 'csrf' => $token];
}

switch ($command) {
    case 'setup':
        $drivers = (int) ($argv[2] ?? 500);
        $passengers = (int) ($argv[3] ?? 100);
        $out = $argv[4] ?? fail('setup needs an output path');
        mt_srand(46);

        if (config('session.driver') !== 'database') {
            fail('Set SESSION_DRIVER=database for the load world (sessions are minted in the table).');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('ehail:geo-reference-areas');
        Artisan::call('ehail:pricing:install-example-tariff');
        config(['money.cash.enabled' => true]);

        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Load Fleet Owner', 'email' => 'load-owner@example.test']);
        $fleet = Fleet::create(['name' => 'Load test fleet', 'owner_user_id' => $owner->id, 'status' => Fleet::STATUS_APPROVED]);
        $requirements = app(DocumentRequirements::class);
        $world = ['created_at' => now()->toIso8601String(), 'connection' => $connection, 'drivers' => [], 'passengers' => []];
        $started = microtime(true);

        for ($i = 0; $i < $drivers; $i++) {
            $user = User::factory()->create(['name' => 'Load Driver '.($i + 1), 'email' => 'load-driver-'.($i + 1).'@example.test']);
            $profile = DriverProfile::factory()->for($user)->create(['status' => 'approved', 'is_online' => false, 'fleet_id' => $fleet->id]);
            $vehicle = Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true, 'type' => 'standard', 'year' => 2022, 'seats' => 4, 'doors' => 4]);
            $rows = [];

            foreach (['driver' => null, 'vehicle' => $vehicle] as $subject => $forVehicle) {
                foreach ($requirements->required($subject, $subject === 'vehicle' ? $vehicle->type : null) as $type => $rule) {
                    $rows[] = [
                        'user_id' => $user->id, 'driver_profile_id' => $profile->id, 'vehicle_id' => $forVehicle?->id, 'market' => 'ZA',
                        'subject' => $forVehicle ? 'vehicle' : 'driver', 'type' => $type, 'status' => 'approved', 'disk' => 'driver-documents',
                        'file_path' => 'load/none', 'original_filename' => 'load (no file)', 'mime' => 'image/jpeg', 'size_bytes' => 0,
                        'sha256' => str_repeat('0', 64), 'expires_on' => $rule['expiry_required'] ? now()->addYear()->toDateString() : null,
                        'submitted_at' => now(), 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }

            DB::table('verification_documents')->insert($rows);
            DriverMoneySetting::updateOrCreate(['user_id' => $user->id], ['accepts_cash' => true]);
            app(AvailabilityService::class)->goOnline($user);
            [$lat, $lng] = around(6000);
            app(AvailabilityService::class)->heartbeat($user, $lat, $lng, 0, 0.0, 8.0);
            $world['drivers'][] = ['id' => $user->id, 'lat' => $lat, 'lng' => $lng] + mintSession($user);
        }

        for ($i = 0; $i < $passengers; $i++) {
            $user = User::factory()->withPersonalTeam()->create(['name' => 'Load Passenger '.($i + 1), 'email' => 'load-passenger-'.($i + 1).'@example.test']);
            [$lat, $lng] = around(5000);
            [$toLat, $toLng] = around(7000);
            $world['passengers'][] = ['id' => $user->id, 'pickup' => [$lat, $lng], 'dropoff' => [$toLat, $toLng]] + mintSession($user);
        }

        $operator = User::factory()->withPersonalTeam()->create(['name' => 'Load Operator', 'email' => 'load-operator@example.test', 'is_platform_operator' => true]);
        $world['operator'] = ['id' => $operator->id] + mintSession($operator);
        $world['setup_seconds'] = round(microtime(true) - $started, 1);

        file_put_contents($out, json_encode($world, JSON_PRETTY_PRINT));
        echo "World ready: {$drivers} online drivers, {$passengers} passengers ({$world['setup_seconds']} s) -> {$out}".PHP_EOL;
        break;

    case 'invariants':
        $result = app(SystemInvariants::class)->check();
        echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
        exit($result['ok'] ? 0 : 1);

    case 'stats':
        echo json_encode([
            'rides_by_status' => DB::table('rides')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'offers_by_status' => DB::table('ride_offers')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'ride_events' => DB::table('ride_events')->count(),
            'trip_points' => DB::table('trip_points')->count(),
            'driver_locations' => DB::table('driver_locations')->count(),
            'availability' => DB::table('driver_availability')->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state'),
            'jobs_pending' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'sessions' => DB::table('sessions')->count(),
        ], JSON_PRETTY_PRINT).PHP_EOL;
        break;

    default:
        echo 'usage: php scripts/load/world.php setup <drivers> <passengers> <out.json> | invariants | stats'.PHP_EOL;
}
