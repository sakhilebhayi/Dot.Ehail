<?php

/*
 * Seeds the five people the signed-in smoke test (tests/Support/signed-in-smoke.sh) signs in as, into the throw-away
 * database that script prepared. Never run against a real database: it refuses unless SMOKE_SEED=1 and the database
 * is a file under the system temp folder. Prints JSON (emails and ids) on stdout.
 *
 *   passenger      a person with a personal team and nothing else
 *   driver         an approved driver in an approved fleet, holding a fleet vehicle, every document approved
 *   operator       users.is_platform_operator (no role rows: full operations access)
 *   fleet owner    owns that fleet
 *   business admin account admin of an approved business account
 */

use App\Domain\Business\BusinessAccounts;
use App\Domain\Fleet\FleetVehicles;
use App\Domain\Fleet\VehicleAssignments;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ApprovesDriverDocuments;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.sqlite.database');
$tmp = rtrim((string) realpath(sys_get_temp_dir()), '/');

if (getenv('SMOKE_SEED') !== '1' || config('database.default') !== 'sqlite' || ! str_starts_with((string) realpath($database), $tmp.'/')) {
    fwrite(STDERR, "smoke-seed refuses: it only seeds the smoke script's temporary SQLite database.\n");
    exit(2);
}

$password = (string) getenv('SMOKE_PASSWORD');

if (strlen($password) < 16) {
    fwrite(STDERR, "smoke-seed: SMOKE_PASSWORD must be set (16+ characters).\n");
    exit(2);
}

$person = function (string $key, string $name) use ($password): User {
    return User::factory()->withPersonalTeam()->create([
        'name' => $name,
        'email' => "{$key}@smoke.test",
        'password' => Hash::make($password),
        'email_verified_at' => now(),
    ]);
};

$passenger = $person('passenger', 'Lindiwe Passenger');
$operator = $person('operator', 'Sipho Operator');
$operator->forceFill(['is_platform_operator' => true])->save();

$fleetOwner = $person('fleet', 'Nomsa Fleet');
$fleet = Fleet::create(['name' => 'Smoke Cabs', 'owner_user_id' => $fleetOwner->id, 'status' => 'approved', 'kind' => 'company']);

$driverUser = $person('driver', 'Thabo Driver');
$profile = DriverProfile::factory()->for($driverUser)->create(['fleet_id' => $fleet->id, 'status' => 'approved']);
$vehicle = app(FleetVehicles::class)->register($fleet, [
    'make' => 'Toyota', 'model' => 'Corolla Quest', 'year' => 2022, 'color' => 'White',
    'plate_number' => 'CA 123 456', 'type' => 'standard', 'seats' => 4, 'doors' => 4, 'features' => [],
], $fleetOwner);
app(VehicleAssignments::class)->grantPermit($fleet, $vehicle, $profile, $fleetOwner);
app(VehicleAssignments::class)->assign($fleet, $vehicle, $profile, $fleetOwner);

(new class
{
    use ApprovesDriverDocuments;

    public function run(DriverProfile $profile, $vehicle): void
    {
        $this->approveAllDocuments($profile, $vehicle->fresh());
    }
})->run($profile, $vehicle);

$businessAdmin = $person('business', 'Ayanda Business');
$accounts = app(BusinessAccounts::class);
$account = $accounts->apply($businessAdmin, [
    'legal_name' => 'Smoke Logistics (Pty) Ltd',
    'registration_number' => '2019/123456/07',
    'billing_email' => 'accounts@smoke.test',
    'address_line1' => '1 Long Street',
    'city' => 'Cape Town',
    'country' => 'ZA',
    'billing_mode' => 'invoice',
]);
$accounts->approve($account, $operator, 1_000_000, 30, 7, null);

echo json_encode([
    'passenger' => $passenger->email,
    'driver' => $driverUser->email,
    'operator' => $operator->email,
    'fleet' => $fleetOwner->email,
    'business' => $businessAdmin->email,
    'fleet_id' => $fleet->id,
    'vehicle_id' => $vehicle->id,
    'business_account_id' => $account->id,
], JSON_UNESCAPED_SLASHES), "\n";
