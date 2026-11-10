<?php

// Direct setup against the throwaway database of the browser E2E suite (tests/e2e/run.mjs): people in their roles,
// fixture drivers, and "time travel" by moving timestamps back. Usage: php tests/e2e/support/world.php <command> '<json>'
// -> prints one JSON line. It refuses to run against any database but the suite's own temporary file.

use App\Domain\Business\BusinessAccounts;
use App\Domain\Fleet\FleetRegistration;
use App\Domain\Ops\Access\OpsRoleAssignment;
use App\Domain\Verification\DocumentRequirements;
use App\Domain\Verification\DocumentStatus;
use App\Domain\Verification\Models\VerificationDocument;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_contains((string) config('database.connections.sqlite.database'), 'dot-ehail-e2e-') || app()->isProduction()) {
    fwrite(STDERR, "refusing to run outside the e2e database\n");
    exit(2);
}

$cmd = $argv[1] ?? '';
$in = json_decode($argv[2] ?? '{}', true) ?: [];
$out = [];

$makeUser = function (array $in): User {
    $user = User::factory()->withPersonalTeam()->create([
        'name' => $in['name'] ?? 'E2E Person', 'email' => $in['email'], 'password' => Hash::make($in['password'] ?? 'e2e-pass-12345'),
    ]);
    $user->forceFill(['email_verified_at' => now()])->save();

    return $user;
};

switch ($cmd) {
    case 'user':
        $user = $makeUser($in);
        $out = ['id' => $user->id, 'email' => $user->email];
        break;

    case 'operator':
        // Roles written as RoleAdministration writes them (the first admin has nobody to grant it).
        $user = $makeUser($in);
        $user->forceFill(['is_platform_operator' => true])->save();
        foreach ($in['roles'] ?? ['admin'] as $role) {
            OpsRoleAssignment::query()->create(['user_id' => $user->id, 'role' => $role]);
        }
        $out = ['id' => $user->id, 'email' => $user->email];
        break;

    case 'verified-driver':
        // A driver who passed onboarding before this test: approved profile in an approved fleet, an active vehicle,
        // every mandatory document approved (the driver journey spec does all of this through the UI instead).
        $user = $makeUser($in);
        $owner = User::where('email', 'fleet-owner-fixture@example.test')->first() ?? $makeUser(['email' => 'fleet-owner-fixture@example.test', 'name' => 'Fixture Fleet Owner']);
        $fleet = Fleet::query()->where('owner_user_id', $owner->id)->first() ?? Fleet::create(['name' => 'Atlantic Seaboard Cabs', 'owner_user_id' => $owner->id, 'status' => Fleet::STATUS_APPROVED]);
        $profile = DriverProfile::factory()->for($user)->create(['status' => 'approved', 'is_online' => false, 'fleet_id' => $fleet->id]);
        $type = $in['type'] ?? 'standard';
        $vehicle = Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true, 'type' => $type, 'year' => 2022, 'seats' => $type === 'suv' ? 6 : 4]);
        $requirements = app(DocumentRequirements::class);
        $image = imagecreatetruecolor(64, 48);
        imagefill($image, 0, 0, imagecolorallocate($image, 90, 120, 160));
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        foreach (($in['documents'] ?? true) ? ['driver' => null, 'vehicle' => $vehicle] : [] as $subject => $forVehicle) {
            foreach ($requirements->required($subject, $subject === 'vehicle' ? $vehicle->type : null) as $docType => $rule) {
                Storage::disk('driver-documents')->put('verification/'.$profile->id.'/'.$docType.'.jpg', $jpeg);
                VerificationDocument::create([
                    'user_id' => $user->id, 'driver_profile_id' => $profile->id, 'vehicle_id' => $forVehicle?->id, 'market' => 'ZA',
                    'subject' => $forVehicle ? 'vehicle' : 'driver', 'type' => $docType, 'status' => DocumentStatus::Approved, 'disk' => 'driver-documents',
                    'file_path' => 'verification/'.$profile->id.'/'.$docType.'.jpg', 'original_filename' => $docType.'.jpg', 'mime' => 'image/jpeg', 'size_bytes' => strlen($jpeg),
                    'sha256' => hash('sha256', $jpeg), 'expires_on' => $rule['expiry_required'] ? now()->addYear()->toDateString() : null, 'submitted_at' => now(), 'reviewed_at' => now(),
                ]);
            }
        }
        $out = ['id' => $user->id, 'email' => $user->email, 'profile_id' => $profile->id, 'vehicle_id' => $vehicle->id, 'fleet_id' => $fleet->id];
        break;

    case 'fleet-owner':
        $user = $makeUser($in);
        $fleet = app(FleetRegistration::class)->register($user, ['name' => $in['fleet'] ?? 'Mother City Cabs', 'kind' => 'company', 'base_city' => 'Cape Town', 'contact_email' => $user->email]);
        $fleet->update(['status' => Fleet::STATUS_APPROVED]);
        $out = ['id' => $user->id, 'email' => $user->email, 'fleet_id' => $fleet->id];
        break;

    case 'business-admin':
        $user = $makeUser($in);
        $accounts = app(BusinessAccounts::class);
        $account = $accounts->apply($user, [
            'legal_name' => 'E2E Freight (Pty) Ltd', 'trading_name' => 'E2E Freight', 'registration_number' => '2019/000000/07', 'billing_email' => 'accounts@e2e-freight.example.test',
            'address_line1' => '12 Bree Street', 'city' => 'Cape Town', 'postal_code' => '8001', 'country' => 'ZA', 'billing_mode' => 'invoice',
        ]);
        $operator = User::where('is_platform_operator', true)->firstOrFail();
        $accounts->approve($account, $operator, 5_000_000, 30, 7, 'E2E account.');
        $out = ['id' => $user->id, 'email' => $user->email, 'account_id' => $account->id];
        break;

    case 'shift':
        // Time travel: move timestamp columns of one row back by N seconds (the app's clock is the real one).
        $row = DB::table($in['table'])->where('id', $in['id'])->first();
        $updates = [];
        foreach ($in['columns'] as $column) {
            if ($row->{$column} !== null) {
                $updates[$column] = \Illuminate\Support\Carbon::parse($row->{$column})->subSeconds((int) $in['seconds'])->format('Y-m-d H:i:s');
            }
        }
        DB::table($in['table'])->where('id', $in['id'])->update($updates);
        $out = ['ok' => true, 'updated' => $updates];
        break;

    case 'update':
        DB::table($in['table'])->where($in['where'])->update($in['set']);
        $out = ['ok' => true];
        break;

    case 'offline-all':
        // Between specs: nobody from an earlier spec stays online to take the next spec's offers.
        $service = app(\App\Domain\Dispatch\AvailabilityService::class);
        $ids = \App\Domain\Dispatch\Models\DriverAvailability::query()->whereIn('state', ['online', 'paused'])->pluck('user_id');
        foreach ($ids as $id) {
            try {
                $service->goOffline(User::findOrFail($id));
            } catch (\Throwable) {
                // On a trip: offline is refused until the trip ends (that driver is never reused).
            }
        }
        // ... and no ride left waiting by an earlier spec (a failed spec can leave one searching) is offered to them.
        $operator = User::where('is_platform_operator', true)->first() ?? $makeUser(['email' => 'e2e-reset-operator@example.test', 'name' => 'E2E Reset']);
        $operator->forceFill(['is_platform_operator' => true])->save();
        $cancelled = 0;
        foreach (\App\Models\Ride::query()->whereIn('status', ['requested', 'searching', 'driver_assigned', 'driver_arriving', 'driver_arrived'])->get() as $ride) {
            try {
                app(\App\Domain\Rides\RideStateMachine::class)->transition($ride, \App\Enums\RideStatus::CancelledByOperator, \App\Enums\RideActor::Operator, $operator, new \App\Domain\Rides\TransitionOptions(reason: 'e2e_reset'));
                $cancelled++;
            } catch (\Throwable) {
                // Already moved on.
            }
        }
        $out = ['offline' => $ids->count(), 'cancelled' => $cancelled];
        break;

    case 'config':
        $out = ['value' => config($in['key'])];
        break;

    default:
        fwrite(STDERR, "unknown command\n");
        exit(1);
}

echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
