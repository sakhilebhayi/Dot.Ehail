<?php

namespace Tests\Feature\Paystack;

use App\Exceptions\Payments\PaymentGatewayException;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackConfig;
use App\Services\Payments\Paystack\PaystackInitialization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesPaystack;
use Tests\TestCase;

class PaystackGuardAndClientTest extends TestCase
{
    use FakesPaystack, RefreshDatabase;

    private function client(): PaystackClient
    {
        return app(PaystackClient::class);
    }

    private function initialize(string $reference = 'ehail-1-abc'): PaystackInitialization
    {
        return $this->client()->initializeTransaction('rider@example.test', 12550, 'ZAR', $reference, 'https://ehail.test/rides/1/pay/callback', ['ride_id' => 1]);
    }

    public function test_no_key_means_payment_is_off_and_nothing_is_sent(): void
    {
        config(['paystack.secret_key' => '']);
        Http::fake();

        $config = app(PaystackConfig::class);
        $this->assertFalse($config->canTakePayments());
        $this->assertNull($config->mode());

        try {
            $this->initialize();
            $this->fail('expected a gateway exception');
        } catch (PaymentGatewayException $e) {
            $this->assertSame('not_configured', $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_a_test_key_is_test_mode(): void
    {
        $this->enablePaystack();

        $config = app(PaystackConfig::class);
        $this->assertTrue($config->canTakePayments());
        $this->assertTrue($config->isTestMode());
    }

    public function test_a_live_key_is_refused_without_the_second_switch_and_never_sent(): void
    {
        $this->enablePaystack('sk_live_do_not_send_this');
        Http::fake();
        Log::spy();

        $config = app(PaystackConfig::class);
        $this->assertFalse($config->canTakePayments());
        $this->assertTrue($config->isRefused());
        $this->assertStringContainsString('PAYSTACK_ALLOW_LIVE', (string) $config->notice());

        try {
            $this->initialize();
            $this->fail('expected a gateway exception');
        } catch (PaymentGatewayException $e) {
            $this->assertSame('live_key_refused', $e->reason);
            $this->assertStringNotContainsString('sk_live', $e->getMessage());
        }

        try {
            $this->client()->verifyTransaction('ehail-1-abc');
            $this->fail('expected a gateway exception');
        } catch (PaymentGatewayException $e) {
            $this->assertSame('live_key_refused', $e->reason);
        }

        Http::assertNothingSent();
        Log::shouldHaveReceived('critical')->with('paystack.key_refused', \Mockery::on(fn ($ctx) => ! str_contains(json_encode($ctx), 'sk_live')));
    }

    public function test_a_live_key_works_only_with_the_second_switch(): void
    {
        $this->enablePaystack('sk_live_deliberate');
        config(['paystack.allow_live' => true]);

        $config = app(PaystackConfig::class);
        $this->assertTrue($config->canTakePayments());
        $this->assertSame('live', $config->mode());
        $this->assertFalse($config->isTestMode());
    }

    public function test_an_unrecognised_key_is_refused(): void
    {
        $this->enablePaystack('pk_test_this_is_a_public_key');

        $this->assertFalse(app(PaystackConfig::class)->canTakePayments());
    }

    public function test_a_non_https_base_url_is_refused(): void
    {
        $this->enablePaystack();
        config(['paystack.base_url' => 'http://api.paystack.co']);
        Http::fake();

        $this->assertFalse(app(PaystackConfig::class)->canTakePayments());
        $this->expectException(PaymentGatewayException::class);

        try {
            $this->initialize();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_initialize_sends_the_documented_request(): void
    {
        $this->enablePaystack();
        $this->fakePaystack();

        $result = $this->initialize('ehail-9-xyz');

        $this->assertStringStartsWith('https://checkout.paystack.com/', $result->authorizationUrl);
        $this->assertSame('ehail-9-xyz', $result->reference);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.paystack.co/transaction/initialize'
                && $request->hasHeader('Authorization', 'Bearer '.self::TEST_KEY)
                && $request['email'] === 'rider@example.test'
                && $request['amount'] === 12550
                && $request['currency'] === 'ZAR'
                && $request['reference'] === 'ehail-9-xyz'
                && $request['callback_url'] === 'https://ehail.test/rides/1/pay/callback';
        });
    }

    public function test_verify_reads_the_transaction_into_a_typed_result(): void
    {
        $this->enablePaystack();
        $this->fakePaystack();
        $payment = $this->paymentFor($this->completedRide());

        $tx = $this->client()->verifyTransaction($payment->reference);

        $this->assertTrue($tx->isSuccessful());
        $this->assertSame(12550, $tx->amountCents);
        $this->assertSame('ZAR', $tx->currency);
        $this->assertSame($payment->reference, $tx->reference);
        $this->assertSame('4099260516', $tx->id);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/transaction/verify/'.$payment->reference));
    }

    public function test_the_safe_payload_drops_customer_and_card_details(): void
    {
        $this->enablePaystack();
        $this->fakePaystack();
        $payment = $this->paymentFor($this->completedRide());

        $summary = json_encode($this->client()->verifyTransaction($payment->reference)->safePayload('verify'));

        foreach (['someone@example.test', 'Secret', '4081', '408408', 'customer', 'authorization'] as $leak) {
            $this->assertStringNotContainsString($leak, $summary);
        }
    }

    public function test_a_timeout_becomes_a_safe_gateway_exception(): void
    {
        $this->enablePaystack();
        $this->fakePaystack();
        $this->initializeThrows = true;

        try {
            $this->initialize();
            $this->fail('expected a gateway exception');
        } catch (PaymentGatewayException $e) {
            $this->assertSame('unavailable', $e->reason);
            $this->assertStringNotContainsString(self::TEST_KEY, $e->getMessage());
            $this->assertStringNotContainsString('cURL', $e->getMessage());
        }
    }

    public function test_server_errors_and_refusals_are_distinguished_and_safe(): void
    {
        $this->enablePaystack();
        $this->fakePaystack();

        $this->initializeStatus = 503;
        try {
            $this->initialize();
            $this->fail('expected');
        } catch (PaymentGatewayException $e) {
            $this->assertSame('unavailable', $e->reason);
        }

        $this->initializeStatus = 401;
        try {
            $this->initialize();
            $this->fail('expected');
        } catch (PaymentGatewayException $e) {
            $this->assertSame('rejected', $e->reason);
            $this->assertStringNotContainsString('401', $e->getMessage());
        }
    }

    public function test_an_initialize_answer_pointing_at_a_foreign_host_is_refused(): void
    {
        $this->enablePaystack();
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => [
            'authorization_url' => 'https://evil.example.test/pay', 'access_code' => 'x', 'reference' => 'ehail-1-abc',
        ]])]);

        $this->expectException(PaymentGatewayException::class);
        $this->initialize();
    }

    public function test_a_malformed_verify_answer_is_refused(): void
    {
        $this->enablePaystack();
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['reference' => 'r', 'status' => 'success']])]);

        try {
            $this->client()->verifyTransaction('r');
            $this->fail('expected');
        } catch (PaymentGatewayException $e) {
            $this->assertSame('invalid_response', $e->reason);
        }
    }

    public function test_redirects_are_not_followed(): void
    {
        $this->enablePaystack();
        Http::fake([
            'api.paystack.co/*' => Http::response('', 302, ['Location' => 'https://evil.example.test/steal']),
            'evil.example.test/*' => Http::response('should never be requested'),
        ]);

        try {
            $this->initialize();
            $this->fail('expected');
        } catch (PaymentGatewayException) {
            // expected
        }

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example.test'));
    }

    public function test_failures_are_logged_without_secrets_payloads_or_addresses(): void
    {
        $this->enablePaystack();
        $this->fakePaystack();
        $this->initializeStatus = 503;
        Log::spy();

        try {
            $this->initialize();
        } catch (PaymentGatewayException) {
            // expected
        }

        Log::shouldHaveReceived('warning')->atLeast()->once()->with('paystack.unavailable', \Mockery::on(function ($context) {
            $flat = json_encode($context);

            return ! str_contains($flat, self::TEST_KEY) && ! str_contains($flat, 'rider@example.test') && ! str_contains($flat, 'callback');
        }));
    }
}
