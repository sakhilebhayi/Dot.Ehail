<?php

/*
 * Upgrade rehearsal, the PHP half of scripts/ops/upgrade-rehearsal.sh (read that file first).
 *
 *   php scripts/ops/upgrade-rehearsal.php seed     fill a database that is at the pre-R-46 schema (main up to
 *                                                  2026_10_20_100100) with legacy rows in every old state
 *   php scripts/ops/upgrade-rehearsal.php verify   after the R-46 migrations: every row is still there, every ride
 *                                                  status was mapped as documented, the timeline and the concurrency
 *                                                  guards were filled, encrypted identifiers still decrypt, legacy
 *                                                  documents were carried over, and the trunk's models read every row
 *
 * Writes only through DB::table (the legacy schema has no models in this code base). Refuses to touch a database
 * unless REHEARSAL=1 is set by the shell script, which only ever points it at a throw-away database.
 */

use App\Enums\RideStatus;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Support\DriverIdentifiers;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Laravel's handler renders an uncaught exception and may still exit 0 in a plain script: make any error a failure.
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'upgrade-rehearsal.php: '.get_class($e).': '.$e->getMessage()."\n");
    exit(1);
});

if (getenv('REHEARSAL') !== '1') {
    fwrite(STDERR, "upgrade-rehearsal.php refuses: run it through scripts/ops/upgrade-rehearsal.sh, which creates a throw-away database.\n");
    exit(2);
}

$mode = $argv[1] ?? '';
$failures = [];
$check = function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? '  ok    ' : '  FAIL  ').$what."\n";

    if (! $ok) {
        $failures[] = $what;
    }
};

/*
 * The legacy rides, keyed by a label stored in pickup_address so verify() can find each one again. Each entry:
 * [old status, passenger key, driver key|null, cancelled_by: 'passenger'|'operator'|null, expected new status].
 */
$rides = [
    'requested-a' => ['requested', 'p1', null, null, 'requested'],
    'requested-b-same-passenger' => ['requested', 'p1', null, null, 'requested'],
    'accepted-d1-older' => ['accepted', 'p2', 'd1', null, 'driver_assigned'],
    'accepted-d1-newer' => ['accepted', 'p3', 'd1', null, 'driver_assigned'],
    'en-route' => ['en_route', 'p4', 'd2', null, 'driver_arriving'],
    'arrived' => ['arrived', 'p5', 'd5', null, 'driver_arrived'],
    'in-progress' => ['in_progress', 'p6', 'd6', null, 'in_progress'],
    'completed-paid' => ['completed', 'p2', 'd2', null, 'completed'],
    'completed-unpaid' => ['completed', 'p7', 'd1', null, 'completed'],
    'completed-waived' => ['completed', 'p8', 'd5', null, 'completed'],
    'cancelled-by-passenger' => ['cancelled', 'p3', null, 'passenger', 'cancelled_by_passenger'],
    'cancelled-by-passenger-after-accept' => ['cancelled', 'p4', 'd2', 'passenger', 'cancelled_by_passenger'],
    'cancelled-by-operator' => ['cancelled', 'p5', 'd6', 'operator', 'cancelled_by_operator'],
    'cancelled-nobody-recorded' => ['cancelled', 'p6', null, null, 'cancelled_by_operator'],
    'expired' => ['expired', 'p7', null, null, 'expired'],
    'needs-review' => ['needs_review', 'p8', 'd4', null, 'needs_review'],
];

$legacyTables = ['users', 'teams', 'team_user', 'fleets', 'driver_profiles', 'vehicles', 'rides', 'ride_ratings', 'ride_payments', 'driver_documents', 'notifications', 'personal_access_tokens'];

if ($mode === 'seed') {
    $now = now()->subDays(3);
    $ids = [];
    $user = function (string $key, string $name) use (&$ids, $now): int {
        $id = DB::table('users')->insertGetId([
            'name' => $name, 'email' => "{$key}@legacy.test", 'password' => bcrypt('legacy-password-'.$key),
            'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $team = DB::table('teams')->insertGetId(['user_id' => $id, 'name' => "{$name}'s Team", 'personal_team' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('users')->where('id', $id)->update(['current_team_id' => $team]);

        return $ids[$key] = $id;
    };

    foreach (range(1, 8) as $n) {
        $user("p{$n}", "Passenger {$n}");
    }

    foreach (range(1, 6) as $n) {
        $user("d{$n}", "Driver {$n}");
    }

    $user('f1', 'Fleet Owner One');
    $user('f2', 'Fleet Owner Two');
    $user('op', 'Operator');
    DB::table('users')->where('id', $ids['op'])->update(['is_platform_operator' => true]);

    // A company team with members, and an InfoDot-linked team.
    $company = DB::table('teams')->insertGetId(['user_id' => $ids['f1'], 'name' => 'Legacy Cabs', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now]);
    DB::table('team_user')->insert([['team_id' => $company, 'user_id' => $ids['d1'], 'role' => 'editor', 'created_at' => $now, 'updated_at' => $now]]);

    $fleet1 = DB::table('fleets')->insertGetId(['name' => 'Legacy Cabs', 'owner_user_id' => $ids['f1'], 'status' => 'approved', 'created_at' => $now, 'updated_at' => $now]);
    $fleet2 = DB::table('fleets')->insertGetId(['name' => 'Pending Fleet', 'owner_user_id' => $ids['f2'], 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
    // A fleet of one: the owner is its only driver.
    $fleet3 = DB::table('fleets')->insertGetId(['name' => 'Driver Six Own', 'owner_user_id' => $ids['d6'], 'status' => 'approved', 'created_at' => $now, 'updated_at' => $now]);

    $profiles = [];
    $vehicles = [];
    $drivers = [
        'd1' => [$fleet1, 'approved', true], 'd2' => [$fleet1, 'approved', false], 'd3' => [$fleet2, 'pending', false],
        'd4' => [$fleet1, 'suspended', false], 'd5' => [$fleet1, 'approved', true], 'd6' => [$fleet3, 'approved', false],
    ];
    $types = ['d1' => 'standard', 'd2' => 'economy', 'd3' => 'standard', 'd4' => 'premium', 'd5' => 'suv', 'd6' => 'standard'];

    foreach ($drivers as $key => [$fleet, $status, $online]) {
        $licence = 'LIC-'.strtoupper($key).'-0001';
        $idNumber = '90010150'.str_pad((string) (100 + (int) substr($key, 1)), 5, '0', STR_PAD_LEFT);
        $profiles[$key] = DB::table('driver_profiles')->insertGetId([
            'user_id' => $ids[$key], 'fleet_id' => $fleet, 'status' => $status, 'is_online' => $online,
            'license_number' => Crypt::encryptString($licence), 'license_number_hash' => DriverIdentifiers::blindIndex($licence),
            'id_number' => Crypt::encryptString($idNumber), 'id_number_hash' => DriverIdentifiers::blindIndex($idNumber),
            'rating' => 4.7, 'total_rides' => 12, 'reviewed_by' => $status === 'pending' ? null : $ids['op'],
            'reviewed_at' => $status === 'pending' ? null : $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $vehicles[$key] = DB::table('vehicles')->insertGetId([
            'driver_profile_id' => $profiles[$key], 'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2019, 'color' => 'White',
            'plate_number' => 'CA '.strtoupper($key).' 001', 'type' => $types[$key], 'is_active' => true, 'seats' => $types[$key] === 'suv' ? 6 : 4,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    // A second, inactive vehicle (only one may be active per driver).
    DB::table('vehicles')->insert(['driver_profile_id' => $profiles['d1'], 'make' => 'VW', 'model' => 'Polo', 'year' => 2015, 'color' => 'Red', 'plate_number' => 'CA D1 002', 'type' => 'economy', 'is_active' => false, 'seats' => 4, 'created_at' => $now, 'updated_at' => $now]);

    // Legacy documents (the old enum: license, id, vehicle_inspection): licence and ID are carried over as pending
    // verification documents; an inspection with a vehicle becomes a roadworthy certificate; one without a vehicle
    // cannot be placed and stays only in driver_documents.
    foreach ([['d1', 'license', null, 'licence.jpg'], ['d1', 'id', null, 'id.pdf'], ['d1', 'vehicle_inspection', 'd1', 'roadworthy.png'], ['d2', 'license', null, 'licence.jpeg'], ['d2', 'vehicle_inspection', null, 'inspection.pdf'], ['d5', 'id', null, 'id-scan.png']] as [$driver, $type, $vehicle, $name]) {
        DB::table('driver_documents')->insert([
            'driver_profile_id' => $profiles[$driver], 'type' => $type, 'vehicle_id' => $vehicle ? $vehicles[$vehicle] : null,
            'file_path' => "driver-documents/{$driver}/".Str::random(12).'-'.$name, 'original_filename' => $name, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $rideIds = [];

    foreach ($rides as $label => [$status, $passenger, $driver, $cancelledBy, $expected]) {
        $completed = $status === 'completed';
        $rideIds[$label] = DB::table('rides')->insertGetId([
            'passenger_id' => $ids[$passenger],
            'driver_id' => $driver ? $ids[$driver] : null,
            'vehicle_id' => $driver ? $vehicles[$driver] : null,
            // Old rides were typed in by hand: some have coordinates, some none at all.
            'pickup_address' => $label, 'dropoff_address' => 'Somewhere in Cape Town',
            'pickup_lat' => str_contains($label, 'paid') ? -33.9249 : null, 'pickup_lng' => str_contains($label, 'paid') ? 18.4241 : null,
            'dropoff_lat' => str_contains($label, 'paid') ? -33.9608 : null, 'dropoff_lng' => str_contains($label, 'paid') ? 18.4766 : null,
            'status' => $status, 'vehicle_type' => $driver ? $types[$driver] : 'standard',
            'estimated_fare' => 87.50, 'final_fare' => $completed ? 92.25 : null, 'distance_km' => 7.3,
            'requested_at' => $now, 'accepted_at' => $driver ? $now : null,
            'started_at' => in_array($status, ['in_progress', 'completed'], true) ? $now : null,
            'completed_at' => $completed ? $now : null,
            'cancelled_by' => match ($cancelledBy) {
                'passenger' => $ids[$passenger], 'operator' => $ids['op'], default => null
            },
            'cancel_reason' => $cancelledBy ? 'changed_plans' : null, 'cancelled_at' => $status === 'cancelled' ? $now : null,
            'expired_at' => $status === 'expired' ? $now : null, 'needs_review_at' => $status === 'needs_review' ? $now : null,
            'payment_status' => match ($label) {
                'completed-paid' => 'paid', 'completed-unpaid' => 'due', 'completed-waived' => 'waived', default => 'not_due'
            },
            'fare_waived_reason' => $label === 'completed-waived' ? 'Operator goodwill' : null,
            'fare_waived_by' => $label === 'completed-waived' ? $ids['op'] : null,
            'fare_waived_at' => $label === 'completed-waived' ? $now : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    DB::table('ride_ratings')->insert([
        ['ride_id' => $rideIds['completed-paid'], 'rated_by' => $ids['p2'], 'rated_user_id' => $ids['d2'], 'rated_role' => 'driver', 'rating' => 5, 'comment' => 'Great', 'created_at' => $now, 'updated_at' => $now],
        ['ride_id' => $rideIds['completed-paid'], 'rated_by' => $ids['d2'], 'rated_user_id' => $ids['p2'], 'rated_role' => 'passenger', 'rating' => 4, 'comment' => null, 'created_at' => $now, 'updated_at' => $now],
    ]);

    foreach ([['completed-paid', 'paid', 'p2'], ['completed-unpaid', 'failed', 'p7'], ['completed-unpaid', 'initiated', 'p7'], [null, 'unmatched', null], ['completed-waived', 'expired', 'p8']] as $n => [$ride, $status, $payer]) {
        DB::table('ride_payments')->insert([
            'ride_id' => $ride ? $rideIds[$ride] : null, 'user_id' => $payer ? $ids[$payer] : null, 'provider' => 'paystack',
            'reference' => 'EHL-LEGACY-'.$n, 'amount_cents' => 9225, 'currency' => 'ZAR', 'status' => $status,
            'provider_transaction_id' => $status === 'paid' || $status === 'unmatched' ? (string) (4_000_000 + $n) : null,
            'paid_at' => $status === 'paid' ? $now : null, 'unmatched_reason' => $status === 'unmatched' ? 'no_ride' : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\RideAccepted', 'notifiable_type' => 'App\\Models\\User',
        'notifiable_id' => $ids['p2'], 'data' => json_encode(['type' => 'ride_accepted', 'title' => 'Driver on the way', 'message' => 'Legacy notification', 'url' => '/rides/'.$rideIds['accepted-d1-older']]),
        'read_at' => null, 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('audit_logs')->insert(['action' => 'driver.approved', 'actor_user_id' => $ids['op'], 'subject_type' => 'App\\Models\\DriverProfile', 'subject_id' => $profiles['d1'], 'meta' => json_encode(['legacy' => true]), 'created_at' => $now]);

    $counts = [];

    foreach (array_merge($legacyTables, ['audit_logs']) as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    echo json_encode(['counts' => $counts, 'rides' => $rideIds, 'users' => $ids, 'profiles' => $profiles, 'vehicles' => $vehicles], JSON_PRETTY_PRINT)."\n";
    exit(0);
}

if ($mode !== 'verify') {
    fwrite(STDERR, "usage: php scripts/ops/upgrade-rehearsal.php seed|verify < seed.json\n");
    exit(2);
}

$seed = json_decode((string) stream_get_contents(STDIN), true);

if (! is_array($seed)) {
    fwrite(STDERR, "verify needs the JSON that seed printed, on stdin.\n");
    exit(2);
}

echo "== rows kept\n";

foreach ($seed['counts'] as $table => $before) {
    $after = DB::table($table)->count();
    $check($table === 'audit_logs' ? $after >= $before : $after === $before, "{$table}: {$before} before, {$after} after");
}

echo "== ride statuses (docs/R46_ARCHITECTURE.md section 2)\n";

foreach ($rides as $label => [$old, , , , $expected]) {
    $row = DB::table('rides')->where('id', $seed['rides'][$label])->first();
    $check($row !== null && $row->status === $expected, "{$label}: {$old} -> ".($row->status ?? 'missing')." (expected {$expected})");

    $migrated = DB::table('ride_events')->where('ride_id', $seed['rides'][$label])->where('type', 'status_migrated')->get();

    if ($old === $expected) {
        $check($migrated->isEmpty(), "{$label}: no status_migrated event (status unchanged)");
    } else {
        $check($migrated->count() === 1 && $migrated->first()->from_status === $old && $migrated->first()->to_status === $expected, "{$label}: one status_migrated event {$old} -> {$expected}");
    }
}

$check(DB::table('rides')->whereIn('status', ['accepted', 'en_route', 'arrived', 'cancelled'])->doesntExist(), 'no ride is left in a retired status');

echo "== concurrency guards\n";
$older = DB::table('rides')->find($seed['rides']['accepted-d1-older']);
$newer = DB::table('rides')->find($seed['rides']['accepted-d1-newer']);
$check((int) $newer->active_driver_id === (int) $seed['users']['d1'] && $older->active_driver_id === null, 'driver d1 held two accepted rides: only the newer one holds the guard');
$check(DB::table('ride_events')->where('ride_id', $older->id)->where('reason_code', 'duplicate_active_driver')->exists(), 'the older one is listed for operators (duplicate_active_driver)');
$a = DB::table('rides')->find($seed['rides']['requested-a']);
$b = DB::table('rides')->find($seed['rides']['requested-b-same-passenger']);
$check((int) $b->active_passenger_id === (int) $seed['users']['p1'] && $a->active_passenger_id === null, 'passenger p1 had two open requests: only the newer one holds the guard');
$check(DB::table('ride_events')->where('ride_id', $a->id)->where('reason_code', 'duplicate_active_passenger')->exists(), 'the older request is listed for operators (duplicate_active_passenger)');

foreach (['completed-paid', 'expired', 'cancelled-by-operator'] as $label) {
    $row = DB::table('rides')->find($seed['rides'][$label]);
    $check($row->active_driver_id === null && $row->active_passenger_id === null, "{$label}: ended ride holds no guard");
}

foreach (['en-route' => 'd2', 'arrived' => 'd5', 'in-progress' => 'd6'] as $label => $driver) {
    $row = DB::table('rides')->find($seed['rides'][$label]);
    $check((int) $row->active_driver_id === (int) $seed['users'][$driver], "{$label}: driver {$driver} holds the guard");
}

$check(DB::table('rides')->whereNotNull('active_driver_id')->select('active_driver_id')->groupBy('active_driver_id')->havingRaw('count(*) > 1')->get()->isEmpty(), 'no driver holds two active rides');

echo "== money and fares kept\n";
$paid = DB::table('rides')->find($seed['rides']['completed-paid']);
$check((float) $paid->final_fare === 92.25 && (float) $paid->estimated_fare === 87.5 && $paid->payment_status === 'paid', 'completed-paid keeps final_fare 92.25, estimate 87.50, payment_status paid');
$check(DB::table('rides')->find($seed['rides']['completed-waived'])->fare_waived_reason === 'Operator goodwill', 'a waived fare keeps its reason');
$check(DB::table('ride_payments')->where('status', 'paid')->value('amount_cents') == 9225, 'ride_payments keep their amounts');

echo "== drivers, vehicles, documents\n";

foreach ($seed['profiles'] as $key => $id) {
    $profile = DriverProfile::withoutGlobalScopes()->find($id);
    $check($profile !== null && $profile->license_number === 'LIC-'.strtoupper($key).'-0001', "driver {$key}: licence number still decrypts with APP_KEY");
}

$check(DB::table('vehicles')->whereNotNull('driver_profile_id')->whereNull('fleet_id')->doesntExist(), 'every held vehicle records its fleet (backfilled from the holder)');
$check(DB::table('fleet_members')->where('role', 'owner')->count() === DB::table('fleets')->count(), 'every fleet owner became an owner member');
$check(DB::table('fleets')->where('owner_user_id', $seed['users']['d6'])->value('kind') === 'independent', 'a fleet whose only driver is its owner is "independent"');
$check(DB::table('fleets')->where('owner_user_id', $seed['users']['f1'])->value('kind') === 'company', 'a fleet with other drivers is "company"');
$check(DB::table('fleets')->where('status', 'approved')->whereNull('approved_at')->doesntExist(), 'approved fleets got approved_at');
$carried = DB::table('verification_documents')->whereNotNull('legacy_driver_document_id')->pluck('type')->sort()->values()->all();
$check($carried === ['drivers_licence', 'drivers_licence', 'identity_document', 'identity_document', 'roadworthy_certificate'], 'legacy licence, ID and inspection-with-vehicle documents became pending verification documents ('.implode(', ', $carried).')');
$check(DB::table('verification_documents')->whereNotNull('legacy_driver_document_id')->where('status', '!=', 'pending')->doesntExist(), 'carried-over documents await review (pending), none auto-approved');
$check(DB::table('driver_documents')->count() === $seed['counts']['driver_documents'], 'the legacy driver_documents rows themselves are kept');

echo "== reference data and the trunk's models\n";
$check(DB::table('ride_categories')->where('is_active', true)->count() > 0, 'ride category catalogue installed by migration');
$check(DB::table('pricing_rule_sets')->count() === 0, 'no prices were installed by migration (the owner publishes the tariff)');

$unreadable = [];

Ride::query()->withoutGlobalScopes()->orderBy('id')->get()->each(function (Ride $ride) use (&$unreadable) {
    try {
        $status = $ride->status;
        RideStatus::from($status instanceof RideStatus ? $status->value : (string) $status);
    } catch (Throwable $e) {
        $unreadable[] = $ride->id.': '.$e->getMessage();
    }
});
$check($unreadable === [], 'every ride loads through the trunk Ride model with a valid RideStatus'.($unreadable ? ' ('.implode('; ', $unreadable).')' : ''));

foreach (['ride_events', 'driver_availability', 'ride_offers', 'ledger_entries', 'driver_earnings', 'payment_methods', 'refunds'] as $table) {
    $check(Schema::hasTable($table), "table {$table} exists");
}

echo "\n";

if ($failures !== []) {
    echo 'UPGRADE REHEARSAL FAILED: '.count($failures)." check(s).\n";
    exit(1);
}

echo "UPGRADE REHEARSAL PASSED.\n";
