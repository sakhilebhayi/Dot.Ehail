<?php

namespace Tests\Feature\Paystack;

use App\Models\RidePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPaystack;
use Tests\TestCase;

class PaymentPrivacyTest extends TestCase
{
    use FakesPaystack, RefreshDatabase;

    public function test_the_raw_request_to_paystack_carries_no_pii_beyond_the_payer_email(): void
    {
        $this->enablePaystack();
        $this->fakePaystack();
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Thandiwe Uniquename Mokoena', 'email' => 'thandi.rider@example.test']);
        $driver = $this->makeApprovedDriver();
        $ride = $this->completedRide($passenger, '125.50', 'unpaid', $driver);
        $ride->forceFill(['dropoff_lat' => -33.8888777, 'dropoff_lng' => 18.5555666, 'cancel_note' => null])->save();

        $this->actingAs($passenger)->post(route('rides.pay', $ride))->assertRedirect();

        /** @var Request $request */
        $request = $this->paystackRequests('/transaction/initialize')->sole();
        $raw = $request->body();

        $this->assertStringContainsString('thandi.rider@example.test', $raw);
        foreach ([
            'Pickup Secret Street', 'Dropoff Hidden Road', 'Thandiwe', 'Uniquename', 'Mokoena', $driver->name, $driver->email,
            '-33.9249', '18.4241', '-33.8888', '18.5555', 'Kloof', 'Long Street',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "'{$secret}' must not be sent to Paystack");
        }

        $this->assertEqualsCanonicalizing(['email', 'amount', 'currency', 'reference', 'callback_url', 'metadata'], array_keys(json_decode($raw, true)));

        $remaining = str_replace('thandi.rider@example.test', '', $raw);
        $this->assertStringNotContainsString('@', $remaining, 'no other email address may be sent');
    }

    public function test_the_verify_request_carries_only_the_reference(): void
    {
        $this->enablePaystack();
        $this->fakePaystack();
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger);
        $this->actingAs($passenger)->post(route('rides.pay', $ride));
        $this->actingAs($passenger)->get(route('rides.pay.callback', $ride));

        $verify = $this->paystackRequests('/transaction/verify')->sole();
        $this->assertSame('', $verify->body());
        $this->assertSame(RidePayment::first()->reference, basename(parse_url($verify->url(), PHP_URL_PATH)));
        $this->assertSame('', (string) parse_url($verify->url(), PHP_URL_QUERY));
    }

    public function test_the_stored_payment_has_no_personal_data(): void
    {
        $this->enablePaystack();
        $this->fakePaystack();
        $passenger = User::factory()->withPersonalTeam()->create(['email' => 'stored.check@example.test']);
        $ride = $this->completedRide($passenger);
        $this->actingAs($passenger)->post(route('rides.pay', $ride));
        $this->actingAs($passenger)->get(route('rides.pay.callback', $ride));

        $row = json_encode(RidePayment::firstOrFail()->getAttributes());
        foreach (['stored.check@example.test', 'Pickup Secret', 'Dropoff Hidden', '4081', 'someone@example.test', 'Secret'] as $leak) {
            $this->assertStringNotContainsString($leak, $row);
        }
    }

    public function test_the_payment_pages_never_print_the_secret_key(): void
    {
        $this->enablePaystack();
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger);

        $this->actingAs($passenger)->get(route('rides.show', $ride))->assertDontSee(self::TEST_KEY);
        $this->actingAs($this->makeOperator())->get(route('operator.rides.index'))->assertDontSee(self::TEST_KEY);
        Http::assertNothingSent();
    }

    public function test_an_unrelated_riders_ride_page_shows_no_payment_controls(): void
    {
        $this->enablePaystack();
        $ride = $this->completedRide();
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($stranger)->get(route('rides.show', $ride))->assertForbidden();
    }
}
