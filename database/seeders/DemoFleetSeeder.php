<?php

namespace Database\Seeders;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Fleet\FleetRegistration;
use App\Domain\Fleet\FleetVehicles;
use App\Domain\Fleet\VehicleAssignments;
use App\Domain\Payments\CashCollection;
use App\Domain\Payments\Models\RidePaymentChoice;
use App\Domain\Payments\RideSettlement;
use App\Domain\Verification\DocumentRequirements;
use App\Domain\Verification\DocumentStatus;
use App\Domain\Verification\Models\VerificationDocument;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * DEV ONLY. A demonstration fleet for the signed-in dev account (dev@example.test), so the fleet portal can be
 * looked at with data in it: drivers, fleet vehicles, assignments, documents (status rows only, no files),
 * maintenance, a vehicle out of service and a few cash trips settled through the real money domain. Every name is
 * labelled demo. Refuses to run in production. Run: php artisan db:seed --class=DemoFleetSeeder
 */
class DemoFleetSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoFleetSeeder is for development only and refuses to run in production.');
        }

        // The local dev login (docs: dev@example.test / dev-pass-123), created when missing.
        $owner = User::where('email', 'dev@example.test')->first()
            ?? User::factory()->withPersonalTeam()->create(['email' => 'dev@example.test', 'name' => 'Dev Owner', 'password' => bcrypt('dev-pass-123')]);

        if (Fleet::where('owner_user_id', $owner->id)->where('name', 'Soweto Cabs (demo)')->exists()) {
            return;
        }

        $fleet = app(FleetRegistration::class)->register($owner, ['name' => 'Soweto Cabs (demo)', 'kind' => 'company', 'base_city' => 'Johannesburg', 'contact_email' => 'fleet-demo@example.test']);
        $fleet->update(['status' => Fleet::STATUS_APPROVED, 'payee_mode' => 'fleet_owner']);

        $drivers = [];
        foreach (['Thabo Mokoena', 'Ayanda Khumalo', 'Lindiwe Ndlovu'] as $i => $name) {
            $user = User::factory()->withPersonalTeam()->create(['name' => $name, 'email' => 'fleet-demo-driver-'.($i + 1).'@example.test']);
            $profile = DriverProfile::factory()->for($user)->create(['fleet_id' => $fleet->id, 'status' => 'approved']);

            foreach (app(DocumentRequirements::class)->required('driver', null) as $type => $rule) {
                // Ayanda's PrDP runs out in 12 days: the expiry alert.
                $expires = $rule['expiry_required'] ? ($i === 1 && $type === 'prdp' ? now()->addDays(12) : now()->addYear())->toDateString() : null;
                $this->document($profile, $type, null, $expires);
            }

            $drivers[] = [$user, $profile];
        }

        $applicant = User::factory()->withPersonalTeam()->create(['name' => 'Sizwe Mahlangu', 'email' => 'fleet-demo-applicant@example.test']);
        DriverProfile::factory()->for($applicant)->create(['fleet_id' => $fleet->id, 'status' => 'pending']);

        $vehicles = app(FleetVehicles::class);
        $assign = app(VehicleAssignments::class);
        $cars = [];
        foreach ([['GP 41 TX', 'Toyota', 'Corolla Quest', 'standard'], ['GP 72 KL', 'Suzuki', 'Dzire', 'economy'], ['GP 15 MN', 'Toyota', 'Fortuner', 'suv'], ['GP 88 RD', 'Hyundai', 'Grand i10', 'economy']] as [$plate, $make, $model, $type]) {
            $cars[] = $vehicles->register($fleet, ['plate_number' => $plate, 'make' => $make, 'model' => $model, 'year' => 2022, 'color' => 'White', 'type' => $type, 'seats' => $type === 'suv' ? 6 : 4, 'doors' => 4, 'features' => ['air_conditioning'], 'odometer_km' => 40000 + 21000 * count($cars)], $owner);
        }

        foreach ([0, 1, 2] as $i) {
            [, $profile] = $drivers[$i];
            $assign->grantPermit($fleet, $cars[$i], $profile, $owner);
            $assign->assign($fleet, $cars[$i], $profile, $owner);
            $car = $cars[$i]->refresh();

            foreach (app(DocumentRequirements::class)->required('vehicle', $car->type) as $type => $rule) {
                // The Fortuner has no roadworthy on record: it cannot go online.
                if ($i === 2 && $type === 'roadworthy_certificate') {
                    continue;
                }
                $this->document($profile, $type, $car, $rule['expiry_required'] ? now()->addMonths(8)->toDateString() : null);
            }
        }
        $assign->grantPermit($fleet, $cars[3], $drivers[0][1], $owner);

        $vehicles->recordMaintenance($fleet, $cars[0], ['kind' => 'service', 'performed_on' => now()->subMonths(4)->toDateString(), 'odometer_km' => 40000, 'description' => 'Minor service: oil, oil filter, air filter', 'cost_minor' => 185000, 'next_service_due_on' => now()->addDays(9)->toDateString(), 'next_service_due_km' => 55000], $owner);
        $vehicles->recordMaintenance($fleet, $cars[1], ['kind' => 'service', 'performed_on' => now()->subMonths(7)->toDateString(), 'odometer_km' => 47000, 'description' => 'Major service and brake pads', 'cost_minor' => 420000, 'next_service_due_km' => 60000], $owner);
        $vehicles->recordMaintenance($fleet, $cars[1], ['kind' => 'tyres', 'performed_on' => now()->subWeek()->toDateString(), 'odometer_km' => 61200, 'description' => 'Two front tyres', 'cost_minor' => 260000], $owner);
        $vehicles->takeOutOfService($fleet, $cars[3]->refresh(), 'Gearbox in for repair', $owner);

        // Thabo is online in his car.
        DriverAvailability::updateOrCreate(['user_id' => $drivers[0][0]->id], ['vehicle_id' => $cars[0]->id, 'state' => DriverAvailability::ONLINE, 'since' => now()->subHour(), 'online_since' => now()->subHour(), 'last_heartbeat_at' => now()]);

        // A few cash trips, settled by the money domain (earnings + ledger), the driver confirming the cash.
        config(['money.cash.enabled' => true]);
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Zanele Mthembu', 'email' => 'fleet-demo-passenger@example.test']);
        foreach ([[0, '128.00', 2], [0, '96.50', 1], [1, '74.00', 1], [0, '210.00', 0]] as [$d, $fare, $daysAgo]) {
            [$driver] = $drivers[$d];
            $ride = Ride::create([
                'passenger_id' => $passenger->id, 'driver_id' => $driver->id, 'vehicle_id' => $cars[$d]->id,
                'pickup_address' => 'Demo pickup', 'dropoff_address' => 'Demo drop-off', 'status' => 'completed',
                'vehicle_type' => $cars[$d]->type, 'estimated_fare' => $fare, 'final_fare' => $fare, 'distance_km' => 9.4,
                'requested_at' => now()->subDays($daysAgo)->subMinutes(40), 'accepted_at' => now()->subDays($daysAgo)->subMinutes(38),
                'completed_at' => now()->subDays($daysAgo),
            ]);
            RidePaymentChoice::updateOrCreate(['ride_id' => $ride->id], ['passenger_id' => $passenger->id, 'method' => RidePaymentChoice::CASH]);
            app(RideSettlement::class)->onTripCompleted($ride->id);
            app(CashCollection::class)->confirm($ride->fresh(), $driver, (int) round(((float) $fare) * 100));
        }
    }

    private function document(DriverProfile $profile, string $type, ?Vehicle $vehicle, ?string $expires): void
    {
        VerificationDocument::create([
            'user_id' => $profile->user_id, 'driver_profile_id' => $profile->id, 'vehicle_id' => $vehicle?->id, 'market' => 'ZA',
            'subject' => $vehicle ? 'vehicle' : 'driver', 'type' => $type, 'status' => DocumentStatus::Approved,
            'disk' => 'driver-documents', 'file_path' => 'demo/none', 'original_filename' => 'demo (no file)', 'mime' => 'image/jpeg',
            'size_bytes' => 0, 'sha256' => str_repeat('0', 64), 'expires_on' => $expires, 'submitted_at' => now()->subMonth(), 'reviewed_at' => now()->subMonth(),
        ]);
    }
}
