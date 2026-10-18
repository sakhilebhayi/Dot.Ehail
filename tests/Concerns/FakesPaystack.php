<?php

namespace Tests\Concerns;

use App\Models\Ride;
use App\Models\RidePayment;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Paystack stand-in for the payment tests. Nothing here (or in the code under test) makes a real
 * HTTP call: Http::fake answers every api.paystack.co request, and Http::preventStrayRequests()
 * turns anything else into a test failure. The key is an obvious fixture, not a real one.
 */
trait FakesPaystack
{
    use CreatesEhailFixtures;

    protected const TEST_KEY = 'sk_test_fixture_for_ehail_tests_only';

    /** @var array<string, mixed> Overrides applied to the next verify answers. */
    protected array $verifyOverrides = [];

    protected int $initializeStatus = 200;

    protected int $verifyStatus = 200;

    protected bool $initializeThrows = false;

    protected function enablePaystack(string $key = self::TEST_KEY): void
    {
        config([
            'paystack.secret_key' => $key,
            'paystack.public_key' => 'pk_test_fixture',
            'paystack.base_url' => 'https://api.paystack.co',
            'paystack.allow_live' => false,
        ]);
    }

    /**
     * Fake both endpoints. initialize echoes our reference back; verify answers from $verifyOverrides
     * over defaults taken from the stored payment row.
     */
    protected function fakePaystack(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'api.paystack.co/transaction/initialize' => function (Request $request) {
                if ($this->initializeThrows) {
                    throw new ConnectionException('cURL error 28: timed out');
                }

                if ($this->initializeStatus !== 200) {
                    return Http::response(['status' => false, 'message' => 'nope'], $this->initializeStatus);
                }

                $reference = $request['reference'];

                return Http::response(['status' => true, 'message' => 'Authorization URL created', 'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/'.substr(md5($reference), 0, 10),
                    'access_code' => substr(md5($reference), 0, 10),
                    'reference' => $reference,
                ]]);
            },
            'api.paystack.co/transaction/verify/*' => function (Request $request) {
                if ($this->verifyStatus !== 200) {
                    return Http::response(['status' => false, 'message' => 'nope'], $this->verifyStatus);
                }

                $reference = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));
                $payment = RidePayment::where('reference', $reference)->first();

                return Http::response(['status' => true, 'message' => 'Verification successful', 'data' => array_merge([
                    'id' => 4099260516,
                    'status' => 'success',
                    'reference' => $reference,
                    'amount' => $payment?->amount_cents ?? 0,
                    'currency' => $payment?->currency ?? 'ZAR',
                    'channel' => 'card',
                    'paid_at' => '2026-10-20T10:00:00.000Z',
                    'gateway_response' => 'Successful',
                    'customer' => ['email' => 'someone@example.test', 'first_name' => 'Secret', 'last_name' => 'Name'],
                    'authorization' => ['last4' => '4081', 'bin' => '408408'],
                ], $this->verifyOverrides)]);
            },
        ]);
    }

    protected function completedRide(?User $passenger = null, string $fare = '125.50', string $paymentStatus = 'unpaid', ?User $driver = null): Ride
    {
        $driver ??= $this->makeApprovedDriver();

        $ride = $this->makeRide([
            'status' => 'completed',
            'driver_id' => $driver->id,
            'final_fare' => $fare,
            'completed_at' => now()->subMinutes(5),
            'pickup_address' => 'Pickup Secret Street 12',
            'dropoff_address' => 'Dropoff Hidden Road 99',
            'pickup_lat' => -33.9249123,
            'pickup_lng' => 18.4241123,
        ], $passenger);

        $ride->forceFill(['payment_status' => $paymentStatus])->save();

        return $ride->fresh();
    }

    protected function paymentFor(Ride $ride, string $status = RidePayment::STATUS_INITIATED, array $attributes = []): RidePayment
    {
        return RidePayment::create(array_merge([
            'ride_id' => $ride->id,
            'user_id' => $ride->passenger_id,
            'provider' => 'paystack',
            'reference' => 'ehail-'.$ride->id.'-'.strtolower(Str::random(12)),
            'amount_cents' => 12550,
            'currency' => 'ZAR',
            'status' => $status,
            'authorization_url' => 'https://checkout.paystack.com/existing',
            'expires_at' => now()->addMinutes(20),
        ], $attributes));
    }

    protected function sign(string $rawBody, string $secret = self::TEST_KEY): string
    {
        return hash_hmac('sha512', $rawBody, $secret);
    }

    /**
     * @param  array<string, mixed>|string  $payload
     */
    protected function postWebhook(array|string $payload, ?string $signature = null, bool $sign = true): TestResponse
    {
        $raw = is_array($payload) ? json_encode($payload) : $payload;
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($signature !== null) {
            $server['HTTP_X_PAYSTACK_SIGNATURE'] = $signature;
        } elseif ($sign) {
            $server['HTTP_X_PAYSTACK_SIGNATURE'] = $this->sign($raw);
        }

        return $this->call('POST', '/webhooks/paystack', [], [], [], $server, $raw);
    }

    /**
     * @return array<string, mixed>
     */
    protected function chargeSuccess(RidePayment $payment, array $dataOverrides = []): array
    {
        return [
            'event' => 'charge.success',
            'data' => array_merge([
                'id' => 4099260516,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => $payment->amount_cents,
                'currency' => $payment->currency,
                'channel' => 'card',
                'paid_at' => '2026-10-20T10:00:00.000Z',
                'customer' => ['email' => 'someone@example.test'],
                'authorization' => ['last4' => '4081'],
            ], $dataOverrides),
        ];
    }

    /**
     * @return Collection<int, Request>
     */
    protected function paystackRequests(string $endpoint): Collection
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), $endpoint))
            ->values();
    }
}
