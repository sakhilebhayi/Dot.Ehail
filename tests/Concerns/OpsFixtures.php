<?php

namespace Tests\Concerns;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\Ledger;
use App\Domain\Ops\Access\RoleAdministration;
use App\Domain\Payments\Models\Payment;
use App\Domain\Safety\IncidentKind;
use App\Domain\Safety\IncidentService;
use App\Domain\Support\SupportCaseService;
use App\Domain\Support\SupportCategory;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Operations-centre test fixtures (tests only, never shipped): operators with roles granted through the real
 * RoleAdministration, and a small operational world created through the real models and domain services.
 */
trait OpsFixtures
{
    use CreatesSafetyFixtures;

    /** @param list<string> $roles */
    protected function operatorWith(array $roles): User
    {
        $admin = $this->makeOperator();
        $user = User::factory()->withPersonalTeam()->create();
        app(RoleAdministration::class)->setRoles($admin, $user, $roles);

        return $user->fresh();
    }

    /** @return array<string, mixed> ids of what was created */
    protected function seedOpsWorld(): array
    {
        Mail::fake();
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Passenger']);
        $driver = $this->makeApprovedDriver('standard', false);
        $driver->forceFill(['name' => 'Sipho Driver'])->save();
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();

        DriverAvailability::query()->create(['user_id' => $driver->id, 'state' => 'online', 'since' => now()->subMinutes(30), 'online_since' => now()->subMinutes(30), 'last_heartbeat_at' => now(), 'last_lat' => -26.2041, 'last_lng' => 28.0473, 'last_location_at' => now()]);

        $searching = $this->makeRide(['status' => 'searching', 'search_started_at' => now()->subMinutes(3), 'pickup_lat' => -26.20, 'pickup_lng' => 28.04, 'dropoff_lat' => -26.10, 'dropoff_lng' => 28.05, 'pickup_address' => 'Searching pickup at Braamfontein']);
        $review = $this->makeRide(['status' => 'needs_review', 'needs_review_at' => now()->subHour(), 'needs_review_reason' => 'stuck_in_progress', 'driver_id' => $driver->id, 'pickup_address' => 'Review pickup at Rosebank']);
        $completed = $this->makeRide(['status' => 'completed', 'driver_id' => $driver->id, 'accepted_at' => now()->subMinutes(50), 'arrived_at' => now()->subMinutes(42), 'started_at' => now()->subMinutes(40), 'completed_at' => now()->subMinutes(10), 'final_fare' => 120, 'final_fare_minor' => 12000, 'currency' => 'ZAR', 'payment_status' => 'paid'], $passenger);
        $expired = $this->makeRide(['status' => 'expired', 'expired_at' => now()->subMinutes(5), 'pickup_address' => 'Expired pickup at Sandton']);

        $payment = Payment::query()->create(['ride_id' => $completed->id, 'user_id' => $passenger->id, 'provider' => 'paystack', 'reference' => 'ops-test-ref-1', 'amount_cents' => 12000, 'currency' => 'ZAR', 'status' => 'paid', 'paid_at' => now()->subMinutes(9), 'purpose' => 'ride_fare', 'attempt' => 1, 'idempotency_key' => 'ride:'.$completed->id.':ride_fare:1']);

        app(Ledger::class)->post((new JournalEntry('trip_revenue', 'trip-revenue:'.$completed->id, 'ZAR', 'Trip completed', ['ride_id' => $completed->id]))
            ->debit(Account::PASSENGER_RECEIVABLE, 12000)
            ->credit(Account::PLATFORM_REVENUE_COMMISSION, 2400)
            ->credit(Account::of(Account::DRIVER_PAYABLE, $driver->id), 9600));

        $incident = app(IncidentService::class)->raise($completed, $passenger, IncidentKind::Other, ['description' => 'Driver took a strange route']);
        $case = app(SupportCaseService::class)->open($passenger, SupportCategory::LostProperty, 'Left my phone', 'I left my phone in the back seat.', ['ride_id' => $completed->id]);

        return [
            'passenger' => $passenger, 'driver' => $driver, 'profile' => $profile,
            'searching' => $searching, 'review' => $review, 'completed' => $completed, 'expired' => $expired,
            'payment' => $payment, 'incident' => $incident['incident'] ?? $incident[0] ?? null, 'case' => $case,
        ];
    }
}
