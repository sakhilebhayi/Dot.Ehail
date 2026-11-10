<?php

namespace Tests\Feature\Dispatch;

use App\Domain\Dispatch\Contracts\FareQuoteGateway;
use App\Domain\Dispatch\Seams\UnavailableFareQuotes;
use App\Domain\Rides\Models\IdempotencyKey;
use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\TestCase;

class RideRequestApiTest extends TestCase
{
    use CreatesDispatchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQuotes();
    }

    public function test_a_ride_is_created_from_a_valid_quote_and_starts_searching(): void
    {
        $passenger = $this->passenger();
        $quote = $this->quotes->issue($passenger);

        $response = $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $quote->id])
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('ride.status', 'searching')
            ->assertJsonPath('ride.fare.quoted_total_minor', 8500)
            ->assertJsonPath('ride.fare.currency', 'ZAR')
            ->assertJsonPath('ride.payment_method', 'cash');

        $ride = Ride::findOrFail($response->json('ride.id'));
        $this->assertSame($quote->id, $ride->fare_quote_id);
        $this->assertSame($quote->id, $ride->quote_snapshot['id']);
        $this->assertSame(85.0, (float) $ride->estimated_fare, 'the decimal column mirrors the minor units');
        $this->assertNotNull($ride->pin_hash);
        $this->assertNull($response->json('ride.pin'), 'the PIN is shown once a driver is assigned');
    }

    public function test_the_client_cannot_set_the_fare(): void
    {
        $passenger = $this->passenger();
        $quote = $this->quotes->issue($passenger, ['totalMinor' => 9900]);

        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), [
            'quote_id' => $quote->id, 'total_minor' => 100, 'estimated_fare' => 1,
        ])->assertCreated()->assertJsonPath('ride.fare.quoted_total_minor', 9900);
    }

    public function test_expired_foreign_unknown_and_used_quotes_are_refused(): void
    {
        $passenger = $this->passenger();
        $someoneElse = $this->passenger();

        $expired = $this->quotes->issue($passenger, ['expiresAt' => now()->subSecond()]);
        $foreign = $this->quotes->issue($someoneElse);

        foreach ([$expired->id, $foreign->id, 'q_does_not_exist'] as $id) {
            $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $id])
                ->assertStatus(422)->assertJsonPath('error', 'quote_invalid');
        }

        $used = $this->quotes->issue($someoneElse);
        $this->actingAs($someoneElse)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $used->id])->assertCreated();
        $this->actingAs($someoneElse)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $used->id])
            ->assertStatus(409)->assertJsonPath('error', 'quote_used');

        $this->assertSame(1, Ride::count());
    }

    public function test_without_pricing_requests_are_refused_honestly(): void
    {
        $this->app->instance(FareQuoteGateway::class, new UnavailableFareQuotes);
        $passenger = $this->passenger();

        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => 'q_anything'])
            ->assertStatus(503)->assertJsonPath('error', 'pricing_unavailable');
        $this->assertSame(0, Ride::count());
    }

    public function test_a_passenger_with_an_active_ride_cannot_request_another(): void
    {
        $passenger = $this->passenger();
        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $this->quotes->issue($passenger)->id])->assertCreated();

        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $this->quotes->issue($passenger)->id])
            ->assertStatus(409)->assertJsonPath('error', 'active_ride_exists');
    }

    public function test_only_cash_until_the_money_domain_is_bound(): void
    {
        $passenger = $this->passenger();

        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $this->quotes->issue($passenger)->id, 'payment_method' => 'card'])
            ->assertStatus(422)->assertJsonPath('error', 'payment_method_unavailable');
    }

    public function test_the_same_idempotency_key_returns_the_same_ride(): void
    {
        $passenger = $this->passenger();
        $quote = $this->quotes->issue($passenger);
        $headers = ['Idempotency-Key' => 'tap-2026-11-10-0001'];

        $first = $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $quote->id], $headers)->assertCreated();
        $second = $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $quote->id], $headers)
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('ride.id'), $second->json('ride.id'));
        $this->assertSame(1, Ride::count());
    }

    public function test_a_reused_key_with_a_different_body_is_refused_and_keys_expire_after_a_day(): void
    {
        $passenger = $this->passenger();
        $headers = ['Idempotency-Key' => 'tap-2026-11-10-0002'];
        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $this->quotes->issue($passenger)->id], $headers)->assertCreated();

        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $this->quotes->issue($passenger)->id], $headers)
            ->assertStatus(422)->assertJsonPath('error', 'idempotency_key_reused');

        $this->travel(25)->hours();
        $this->artisan('ehail:dispatch-tick')->assertExitCode(0);
        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_a_refusal_is_replayed_for_the_same_key(): void
    {
        $passenger = $this->passenger();
        $headers = ['Idempotency-Key' => 'tap-2026-11-10-0003'];

        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => 'q_missing'], $headers)->assertStatus(422);
        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => 'q_missing'], $headers)
            ->assertStatus(422)->assertJsonPath('error', 'quote_invalid');
    }

    public function test_guests_cannot_request(): void
    {
        $this->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => 'q_x'])->assertUnauthorized();
    }
}
