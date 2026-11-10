<?php

/**
 * R-46 a11y (development only): prints the ids of real records the sweep opens (a support case, an incident, a
 * statement, a fleet car...), so detail pages are judged with real content. Refuses to run in production.
 *   php scripts/dev/a11y/ids.php
 */

use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessStatement;
use App\Domain\Payments\Models\Payment;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Support\Models\SupportCase;
use App\Domain\Verification\Models\VerificationDocument;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment('production')) {
    fwrite(STDERR, "ids.php is for development only.\n");
    exit(1);
}

$passenger = User::where('email', 'dev@example.test')->first();
$driver = User::where('email', 'a11y-driver@example.test')->first();
$profile = DriverProfile::withoutGlobalScopes()->where('user_id', $driver?->id)->first();
$fleet = \App\Models\Fleet::withoutGlobalScopes()->where('owner_user_id', $passenger?->id)->first();
$q = fn ($query) => $query->withoutGlobalScopes();

echo json_encode([
    'case' => $q(SupportCase::query())->where('user_id', $passenger?->id)->latest('id')->value('id'),
    'incident' => $q(SafetyIncident::query())->where('reporter_user_id', $passenger?->id)->latest('id')->value('id')
        ?? $q(SafetyIncident::query())->latest('id')->value('id'),
    'statement' => $q(BusinessStatement::query())->latest('id')->value('id'),
    'account' => $q(BusinessAccount::query())->latest('id')->value('id'),
    'fleetVehicle' => $fleet ? $q(Vehicle::query())->where('fleet_id', $fleet->id)->value('id') : null,
    'fleetDriver' => $fleet ? $q(DriverProfile::query())->where('fleet_id', $fleet->id)->value('id') : null,
    'driverRide' => $q(Ride::query())->where('driver_id', $driver?->id)->latest('id')->value('id'),
    'opsRide' => $q(Ride::query())->latest('id')->value('id'),
    'vehicle' => $profile ? $q(Vehicle::query())->where('driver_profile_id', $profile->id)->value('id') : null,
    'driverProfile' => $profile?->id,
    'driverUser' => $driver?->id,
    'document' => $q(VerificationDocument::query())->latest('id')->value('id'),
    'ruleSet' => $q(PricingRuleSet::query())->latest('id')->value('id'),
    'active' => $q(Ride::query())->where(fn ($w) => $w->where('passenger_id', $passenger?->id)->orWhere('driver_id', $driver?->id))
        ->whereNotIn('status', ['completed', 'cancelled_by_passenger', 'cancelled_by_driver', 'cancelled_by_operator', 'expired', 'no_show', 'failed', 'cancelled'])->pluck('id'),
    'payment' => $q(Payment::query())->latest('id')->value('id'),
]);
