<?php

namespace Tests\Feature\Paystack;

use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\RidePayment;
use App\Models\User;
use App\Services\Ehail\RideLifecycle;
use App\Services\Ehail\RidePayments;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesPaystack;
use Tests\TestCase;

class RidePaymentStateTest extends TestCase
{
    use FakesPaystack, RefreshDatabase;

    private function driverCompletes(Ride $ride): Ride
    {
        return app(RideLifecycle::class)->advance($ride, $ride->driver, RideStatus::Completed);
    }

    private function inProgressRide(string $estimate = '80.00'): Ride
    {
        $driver = $this->makeApprovedDriver();

        return $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id, 'estimated_fare' => $estimate, 'started_at' => now()])
            ->load('driver');
    }

    public function test_a_ride_completed_while_paystack_is_configured_becomes_unpaid(): void
    {
        $this->enablePaystack();
        $ride = $this->driverCompletes($this->inProgressRide('80.00'));

        $this->assertSame('unpaid', $ride->fresh()->payment_status);
    }

    public function test_a_ride_completed_without_paystack_is_not_due_and_everything_else_is_unchanged(): void
    {
        config(['paystack.secret_key' => '']);
        $ride = $this->driverCompletes($this->inProgressRide('80.00'));

        $fresh = $ride->fresh();
        $this->assertSame('not_due', $fresh->payment_status);
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('80.00', (string) $fresh->final_fare);
    }

    public function test_a_refused_live_key_counts_as_not_configured_at_completion(): void
    {
        $this->enablePaystack('sk_live_not_allowed');
        $ride = $this->driverCompletes($this->inProgressRide());

        $this->assertSame('not_due', $ride->fresh()->payment_status);
    }

    public function test_a_zero_fare_is_not_due(): void
    {
        $this->enablePaystack();
        $ride = $this->driverCompletes($this->inProgressRide('0.00'));

        $this->assertSame('not_due', $ride->fresh()->payment_status);
    }

    public function test_an_operator_entered_fare_makes_the_ride_unpaid(): void
    {
        $this->enablePaystack();
        $operator = $this->makeOperator();
        $ride = $this->makeRide(['status' => 'needs_review', 'driver_id' => $this->makeApprovedDriver()->id, 'estimated_fare' => null, 'needs_review_at' => now()]);

        app(RideLifecycle::class)->operatorComplete($ride, $operator, 210.75, 'Confirmed with the driver');

        $this->assertSame('unpaid', $ride->fresh()->payment_status);
        $this->assertSame(21075, app(RidePayments::class)->fareCents($ride->fresh()));
    }

    public function test_a_new_ride_starts_not_due(): void
    {
        $this->assertSame('not_due', $this->makeRide()->fresh()->payment_status);
    }

    #[DataProvider('fareProvider')]
    public function test_the_amount_is_the_stored_fare_in_integer_cents(string $stored, int $cents): void
    {
        $ride = $this->completedRide(null, $stored);

        $this->assertSame($cents, app(RidePayments::class)->fareCents($ride));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function fareProvider(): array
    {
        return [
            'whole rand' => ['80', 8000],
            'cents' => ['125.50', 12550],
            'one cent' => ['0.01', 1],
            'awkward float' => ['19.99', 1999],
            'another awkward float' => ['1.15', 115],
            'column maximum' => ['999999.99', 99999999],
        ];
    }

    public function test_the_payment_status_column_cannot_be_mass_assigned(): void
    {
        $ride = $this->makeRide();
        $ride->fill(['payment_status' => 'paid'])->save();

        $this->assertSame('not_due', $ride->fresh()->payment_status);
    }

    public function test_a_provider_transaction_id_can_belong_to_one_payment_only(): void
    {
        $ride = $this->completedRide();
        $this->paymentFor($ride, RidePayment::STATUS_PAID, ['provider_transaction_id' => '777']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->paymentFor($ride, RidePayment::STATUS_PAID, ['provider_transaction_id' => '777']);
    }

    public function test_several_payments_may_have_no_transaction_id_yet(): void
    {
        $ride = $this->completedRide();
        $this->paymentFor($ride);
        $this->paymentFor($ride);

        $this->assertSame(2, RidePayment::count());
    }

    public function test_a_reference_is_unique(): void
    {
        $ride = $this->completedRide();
        $this->paymentFor($ride, RidePayment::STATUS_INITIATED, ['reference' => 'dup-ref']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->paymentFor($ride, RidePayment::STATUS_INITIATED, ['reference' => 'dup-ref']);
    }

    public function test_migrating_legacy_rides_leaves_them_not_due(): void
    {
        $passenger = User::factory()->create();
        $this->artisan('migrate:rollback', ['--step' => 2])->assertExitCode(0);

        $this->assertFalse(Schema::hasColumn('rides', 'payment_status'));
        $this->assertFalse(Schema::hasTable('ride_payments'));

        foreach (['completed' => '90.00', 'cancelled' => null] as $status => $fare) {
            DB::table('rides')->insert([
                'passenger_id' => $passenger->id, 'pickup_address' => 'A', 'dropoff_address' => 'B',
                'status' => $status, 'vehicle_type' => 'standard', 'final_fare' => $fare,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertSame(2, DB::table('rides')->where('payment_status', 'not_due')->count());
        $this->assertSame(0, DB::table('ride_payments')->count());
    }
}
