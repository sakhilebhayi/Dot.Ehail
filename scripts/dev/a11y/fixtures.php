<?php

/**
 * R-46 a11y (development only): the people and reference data the accessibility sweep signs in as.
 * Refuses to run in production. Idempotent: safe to run again.
 *   php scripts/dev/a11y/fixtures.php
 *
 * dev@example.test       passenger, business admin (DemoBusinessSeeder) and fleet owner (DemoFleetSeeder)
 * ops-demo@example.test  platform operator (DemoBusinessSeeder)
 * a11y-driver@example.test  a verified Cape Town driver with an active car and approved documents
 * a11y-new@example.test  signed in, not a driver yet
 * Every password: dev-pass-123
 */

use App\Domain\Verification\DocumentRequirements;
use App\Domain\Verification\DocumentStatus;
use App\Domain\Verification\Models\VerificationDocument;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->environment('production')) {
    fwrite(STDERR, "fixtures.php is for development only and refuses to run in production.\n");
    exit(1);
}

$person = function (string $email, string $name): User {
    $user = User::where('email', $email)->first()
        ?? User::forceCreate(['name' => $name, 'email' => $email, 'password' => Hash::make('dev-pass-123'), 'email_verified_at' => now()]);
    $user->forceFill(['password' => Hash::make('dev-pass-123'), 'email_verified_at' => now()])->save();
    if (! $user->currentTeam) {
        $team = $user->ownedTeams()->create(['name' => $name."'s team", 'personal_team' => true]);
        $user->forceFill(['current_team_id' => $team->id])->save();
    }

    return $user;
};

$person('dev@example.test', 'Thandi Mokoena');
$person('a11y-new@example.test', 'Lerato Dlamini');
foreach (['EhailPricingExampleSeeder', 'GeoReferenceAreasSeeder', 'DemoBusinessSeeder', 'DemoFleetSeeder'] as $seeder) {
    $marker = storage_path('a11y/seeded-'.$seeder);
    if (is_file($marker)) {
        continue;
    }
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\'.$seeder, '--force' => true]);
    @mkdir(dirname($marker), 0775, true);
    touch($marker);
    echo "seeded $seeder\n";
}

$ops = User::where('email', 'ops-demo@example.test')->firstOrFail();
$ops->forceFill(['is_platform_operator' => true])->save();

$driver = $person('a11y-driver@example.test', 'Sipho Ndlovu');
$fleet = Fleet::firstOrCreate(['name' => 'Gardens Cabs (dev)'], ['owner_user_id' => $ops->id, 'status' => 'approved']);
$profile = DriverProfile::withoutGlobalScope('user')->firstOrCreate(['user_id' => $driver->id], [
    'fleet_id' => $fleet->id, 'license_number' => 'DEVLIC'.random_int(1000, 9999), 'id_number' => 'DEVID'.random_int(100000, 999999), 'status' => 'approved',
]);
$vehicle = Vehicle::firstOrCreate(['driver_profile_id' => $profile->id], [
    'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2021, 'color' => 'White', 'plate_number' => 'CA 123 456', 'type' => 'standard', 'seats' => 4, 'is_active' => true,
]);
$requirements = app(DocumentRequirements::class);
foreach (['driver' => null, 'vehicle' => $vehicle] as $subject => $forVehicle) {
    foreach ($requirements->required($subject, $forVehicle?->type) as $type => $rule) {
        if (VerificationDocument::where('driver_profile_id', $profile->id)->where('type', $type)->exists()) {
            continue;
        }
        VerificationDocument::create([
            'user_id' => $driver->id, 'driver_profile_id' => $profile->id, 'vehicle_id' => $forVehicle?->id, 'market' => 'ZA',
            'subject' => $subject, 'type' => $type, 'status' => DocumentStatus::Approved, 'disk' => 'driver-documents',
            'file_path' => 'dev/'.$type.'.jpg', 'original_filename' => $type.'.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10,
            'sha256' => str_repeat('a', 64), 'expires_on' => $rule['expiry_required'] ? now()->addYear()->toDateString() : null,
            'submitted_at' => now(), 'reviewed_at' => now(),
        ]);
    }
}
echo "fixtures ready\n";
