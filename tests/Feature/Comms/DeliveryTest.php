<?php

namespace Tests\Feature\Comms;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\DeliveryWorker;
use App\Domain\Comms\Mail\CommsMail;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Models\PushSubscription;
use App\Domain\Comms\NotificationRouter;
use App\Domain\Comms\Push\Base64Url;
use App\Domain\Comms\Push\PayloadEncrypter;
use App\Domain\Comms\Push\WebPushClient;
use App\Domain\Comms\Models\SmsNumber;
use App\Domain\Safety\Notifications\SafetyUpdateNotification;
use App\Domain\Safety\Sms\SmsGateway;
use App\Domain\Safety\Sms\SmsResult;
use App\Jobs\SendNotifyEvent;
use App\Models\User;
use App\Notifications\RideCompletedNotification;
use App\Notifications\RideNoticeNotification;
use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesCommsFixtures;
use Tests\TestCase;

/**
 * Idempotency, retries with backoff, honest mail status, push subscription lifecycle against a
 * faked push service (the body is decrypted with the browser's key to prove what was sent), SMS
 * gating, and the Dot.Notify precedence rule.
 */
class DeliveryTest extends TestCase
{
    use CreatesCommsFixtures, RefreshDatabase;

    private function message(User $user, string $type = 'driver_arrived', string $key = 'ride:1:driver_arrived:0', ?int $actor = null): CommsMessage
    {
        return new CommsMessage($user, $type, $key, 'Your driver has arrived', 'Thabo is waiting at the pickup point.', '/rides/1', 'ride-1', $actor);
    }

    public function test_the_same_event_twice_is_one_notification_and_one_delivery_per_channel(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $this->configurePush();
        $user = User::factory()->create();
        $this->subscribe($user);

        $router = app(NotificationRouter::class);
        $this->assertNotNull($router->send($this->message($user)));
        $this->assertNull($router->send($this->message($user)), 'a duplicate returns null and writes nothing');

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(['email', 'in_app', 'push'], $this->deliveries($user)->pluck('channel')->sort()->values()->all());
        Http::assertSentCount(1);

        // Running the worker again (a retry, a second cron run) sends nothing more.
        app(DeliveryWorker::class)->deliverDue();
        app(DeliveryWorker::class)->deliverIds($this->deliveries($user)->pluck('id')->all());
        Http::assertSentCount(1);
    }

    public function test_a_push_carries_the_encrypted_notification_with_vapid_headers(): void
    {
        Http::fake(['*' => Http::response('', 201, ['Location' => 'https://fcm.googleapis.com/fcm/send/abc/msg-123'])]);
        $this->configurePush();
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);

        app(NotificationRouter::class)->send($this->message($user));

        Http::assertSent(function (HttpRequest $request) use ($subscription) {
            $keys = $this->browserKeys[$subscription->id];
            $payload = json_decode((new PayloadEncrypter)->decrypt($request->body(), $keys['private'], $keys['public'], $keys['auth']), true);

            return $request->url() === $subscription->endpoint
                && $request->header('Content-Encoding')[0] === 'aes128gcm'
                && $request->header('TTL')[0] === '3600'
                && str_starts_with($request->header('Authorization')[0], 'vapid t=')
                && str_contains($request->header('Authorization')[0], ', k='.config('ehail_comms.push.public_key'))
                && strlen($request->header('Topic')[0]) === 32
                && $payload['title'] === 'Your driver has arrived'
                && $payload['url'] === '/rides/1'
                && $payload['tag'] === 'ride-1';
        });

        $push = $this->delivery($user, 'driver_arrived', 'push');
        $this->assertSame(NotificationDelivery::SENT, $push->status);
        $this->assertSame('msg-123', $push->provider_id, 'only the message id is kept, not the endpoint URL');
        $this->assertNotNull($subscription->fresh()->last_success_at);
    }

    public function test_the_vapid_token_is_a_valid_es256_jwt_for_the_push_service_origin(): void
    {
        $this->configurePush();
        $header = app(\App\Domain\Comms\Push\VapidKeys::class)->authorization('https://fcm.googleapis.com');
        preg_match('/^vapid t=([^.]+)\.([^.]+)\.([^,]+), k=(.+)$/', $header, $m);

        $claims = json_decode(Base64Url::decode($m[2]), true);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
        $this->assertSame('mailto:no-reply@infodot.co.za', $claims['sub']);
        $this->assertLessThanOrEqual(now()->addDay()->timestamp, $claims['exp'], 'RFC 8292: at most 24 hours');

        $public = \App\Domain\Comms\Push\EcKeys::publicKey(Base64Url::decode($m[4]));
        $this->assertSame(1, openssl_verify($m[1].'.'.$m[2], \Tests\Unit\Comms\PayloadEncrypterTest::der(Base64Url::decode($m[3])), $public, OPENSSL_ALGO_SHA256));
    }

    public function test_a_gone_subscription_is_revoked_and_the_delivery_failed_never_sent(): void
    {
        Http::fake(['*' => Http::response('', 410)]);
        $this->configurePush();
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);

        app(NotificationRouter::class)->send($this->message($user));

        $push = $this->delivery($user, 'driver_arrived', 'push');
        $this->assertSame(NotificationDelivery::FAILED, $push->status);
        $this->assertSame('subscription_gone', $push->reason);
        $this->assertNotNull($subscription->fresh()->revoked_at);
        $this->assertSame('subscription_gone', $subscription->fresh()->revoke_reason);
    }

    public function test_temporary_failures_retry_with_backoff_then_give_up(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $this->configurePush();
        $user = User::factory()->create();
        $this->subscribe($user);

        app(NotificationRouter::class)->send($this->message($user, 'payout_paid', 'payout:1:paid'));
        $push = $this->delivery($user, 'payout_paid', 'push');
        $this->assertSame(NotificationDelivery::QUEUED, $push->status);
        $this->assertSame(1, $push->attempts);
        $this->assertSame('push_http_503', $push->reason);
        $this->assertTrue($push->next_attempt_at->between(now()->addSeconds(55), now()->addSeconds(65)), 'first retry after 60 s');

        app(DeliveryWorker::class)->deliverDue();
        $this->assertSame(1, $push->fresh()->attempts, 'not due yet: untouched');

        foreach ([61, 301, 901, 3601] as $wait) {
            $this->travel($wait)->seconds();
            app(DeliveryWorker::class)->deliverDue();
        }

        $push->refresh();
        $this->assertSame(5, $push->attempts);
        $this->assertSame(NotificationDelivery::FAILED, $push->status);
        $this->assertNull($push->sent_at);
    }

    public function test_a_retry_that_then_succeeds_is_sent(): void
    {
        Http::fakeSequence()->push('', 503)->push('', 201);
        $this->configurePush();
        $user = User::factory()->create();
        $this->subscribe($user);

        app(NotificationRouter::class)->send($this->message($user, 'payout_paid', 'payout:2:paid'));
        $this->travel(61)->seconds();
        app(DeliveryWorker::class)->deliverDue();

        $push = $this->delivery($user, 'payout_paid', 'push');
        $this->assertSame(NotificationDelivery::SENT, $push->status);
        $this->assertSame(2, $push->attempts);
    }

    public function test_an_abandoned_claim_is_picked_up_again(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $this->configurePush();
        $user = User::factory()->create();
        $this->subscribe($user);
        Bus::fake();

        app(NotificationRouter::class)->send($this->message($user));
        $push = $this->delivery($user, 'driver_arrived', 'push');
        $push->forceFill(['status' => NotificationDelivery::SENDING, 'claimed_until' => now()->subSecond()])->save();

        app(DeliveryWorker::class)->deliverDue();
        $this->assertSame(NotificationDelivery::SENT, $push->fresh()->status);
    }

    public function test_an_older_queued_push_about_the_same_ride_is_superseded(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        Bus::fake(); // keep both queued, then run the worker once
        $this->configurePush();
        $user = User::factory()->create();
        $this->subscribe($user);

        $router = app(NotificationRouter::class);
        $router->send(new CommsMessage($user, 'driver_assigned', 'ride:1:driver_assigned:0', 'On the way', 'x', '/rides/1', 'ride-1'));
        $router->send(new CommsMessage($user, 'driver_arrived', 'ride:1:driver_arrived:0', 'Arrived', 'y', '/rides/1', 'ride-1'));

        app(DeliveryWorker::class)->deliverDue();

        $this->assertSame('superseded', $this->delivery($user, 'driver_assigned', 'push')->reason);
        $this->assertSame(NotificationDelivery::SENT, $this->delivery($user, 'driver_arrived', 'push')->status);
        Http::assertSentCount(1);
        $this->assertSame(2, $user->notifications()->count(), 'the inbox keeps both');
    }

    public function test_no_push_or_sms_for_your_own_action(): void
    {
        Http::fake();
        $this->configurePush();
        $user = User::factory()->create();
        $this->subscribe($user);

        app(NotificationRouter::class)->send($this->message($user, actor: $user->id));

        $this->assertSame('own_action', $this->delivery($user, 'driver_arrived', 'push')->reason);
        Http::assertNothingSent();
    }

    public function test_push_subscription_lifecycle_through_the_api(): void
    {
        $user = User::factory()->create();
        $keys = \App\Domain\Comms\Push\EcKeys::generate();
        $body = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => Base64Url::encode($keys['public']), 'auth' => Base64Url::encode(random_bytes(16))]];

        $this->actingAs($user)->postJson(route('ehail.comms.push.subscribe'), $body)->assertStatus(503)->assertJsonPath('error', 'push_not_configured');

        $this->configurePush();
        $this->actingAs($user)->getJson(route('ehail.comms.push.key'))->assertOk()->assertJsonPath('configured', true)->assertJsonPath('public_key', config('ehail_comms.push.public_key'));

        $this->actingAs($user)->postJson(route('ehail.comms.push.subscribe'), ['endpoint' => 'https://evil.example.com/push'] + $body)->assertStatus(422)->assertJsonPath('error', 'subscription_refused');
        $this->actingAs($user)->postJson(route('ehail.comms.push.subscribe'), ['keys' => ['p256dh' => 'bad', 'auth' => 'bad']] + $body)->assertStatus(422);

        $this->actingAs($user)->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1')
            ->postJson(route('ehail.comms.push.subscribe'), $body)->assertCreated()->assertJsonPath('device', 'Safari on iPhone');
        $this->actingAs($user)->postJson(route('ehail.comms.push.subscribe'), $body)->assertCreated();
        $this->assertSame(1, PushSubscription::count(), 'one row per endpoint');
        $this->assertStringNotContainsString('fcm.googleapis.com', (string) \DB::table('push_subscriptions')->value('endpoint'), 'encrypted at rest');

        $this->actingAs($user)->postJson(route('ehail.comms.push.status'), ['endpoint' => $body['endpoint']])->assertJsonPath('subscribed', true);

        // Another account on the same browser takes the subscription over.
        $other = User::factory()->create();
        $this->actingAs($other)->postJson(route('ehail.comms.push.subscribe'), $body)->assertCreated();
        $this->actingAs($user)->postJson(route('ehail.comms.push.status'), ['endpoint' => $body['endpoint']])->assertJsonPath('subscribed', false);

        $this->actingAs($other)->deleteJson(route('ehail.comms.push.unsubscribe'), ['endpoint' => $body['endpoint']])->assertOk()->assertJsonPath('removed', true);
        $this->assertNotNull(PushSubscription::first()->revoked_at);
        $this->assertSame('turned_off', PushSubscription::first()->revoke_reason);
    }

    public function test_the_test_push_reports_what_the_push_service_said(): void
    {
        $this->configurePush();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('ehail.comms.push.test'))->assertStatus(422)->assertJsonPath('error', 'no_devices');

        $this->subscribe($user);
        Http::fakeSequence()->push('', 201)->push('', 410);
        $this->actingAs($user)->postJson(route('ehail.comms.push.test'))->assertOk()->assertJsonPath('accepted', 1);

        $this->actingAs($user)->postJson(route('ehail.comms.push.test'))->assertOk()->assertJsonPath('accepted', 0);
        $this->assertSame(0, PushSubscription::active()->count());
    }

    public function test_only_known_push_services_are_ever_called(): void
    {
        $this->assertTrue(WebPushClient::endpointAllowed('https://fcm.googleapis.com/fcm/send/x'));
        $this->assertTrue(WebPushClient::endpointAllowed('https://web.push.apple.com/QGx'));
        $this->assertTrue(WebPushClient::endpointAllowed('https://updates.push.services.mozilla.com/wpush/v2/x'));
        $this->assertFalse(WebPushClient::endpointAllowed('http://fcm.googleapis.com/x'), 'https only');
        $this->assertFalse(WebPushClient::endpointAllowed('https://fcm.googleapis.com.evil.test/x'));
        $this->assertFalse(WebPushClient::endpointAllowed('https://user:pw@fcm.googleapis.com/x'));
        $this->assertFalse(WebPushClient::endpointAllowed('https://127.0.0.1/x'));
    }

    public function test_email_is_never_reported_sent_while_the_mailer_only_logs(): void
    {
        config(['mail.default' => 'log']);
        $user = User::factory()->create();
        app(NotificationRouter::class)->send($this->message($user, 'payout_paid', 'payout:3:paid'));

        $email = $this->delivery($user, 'payout_paid', 'email');
        $this->assertSame(NotificationDelivery::SKIPPED, $email->status);
        $this->assertSame('mail_transport_not_delivering', $email->reason);
        $this->assertSame('Email: not delivered: email is only logged on this service', $email->toPublicArray()['label']);

        $this->actingAs($user)->getJson(route('ehail.comms.preferences'))->assertJsonPath('channels.email.available', false)
            ->assertJsonPath('channels.email.state', 'not_delivering');
    }

    public function test_email_is_sent_when_a_real_mailer_accepts_it_and_retried_when_it_throws(): void
    {
        $this->deliveringMail();
        Mail::fake();
        $user = User::factory()->create();

        app(NotificationRouter::class)->send($this->message($user, 'payout_paid', 'payout:4:paid'));
        Mail::assertSent(CommsMail::class, fn (CommsMail $mail) => $mail->hasTo($user->email) && $mail->heading === 'Your driver has arrived' && $mail->actionUrl === url('/rides/1'));
        $this->assertSame(NotificationDelivery::SENT, $this->delivery($user, 'payout_paid', 'email')->status);
        $this->assertSame('mail:smtp', $this->delivery($user, 'payout_paid', 'email')->provider);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        app(NotificationRouter::class)->send($this->message($user, 'payout_failed', 'payout:5:failed'));
        $email = $this->delivery($user, 'payout_failed', 'email');
        $this->assertSame(NotificationDelivery::QUEUED, $email->status);
        $this->assertSame('mail_error_runtimeexception', $email->reason);
    }

    public function test_the_mail_view_renders(): void
    {
        $html = (new CommsMail('Payout paid', 'Your payout of R1,250.00 has been paid out.', 'https://ehail.test/money/driver/payouts/1', 'Payouts and incentives', 'https://ehail.test/notifications/settings'))->render();

        $this->assertStringContainsString('Payout paid', $html);
        $this->assertStringContainsString('notification settings', $html);
    }

    public function test_sms_is_only_for_safety_and_only_to_a_confirmed_number_when_a_provider_exists(): void
    {
        $user = User::factory()->create();
        $user->notify(new SafetyUpdateNotification('safety_check_in', 'Are you OK?', 'Your trip stopped for a while.'));
        $this->assertSame('sms_not_configured', $this->delivery($user, 'safety_check_in', 'sms')->reason);

        $sms = $this->fakeSms();
        $other = User::factory()->create();
        $other->notify(new SafetyUpdateNotification('safety_check_in', 'Are you OK?', 'Your trip stopped for a while.'));
        $this->assertSame('no_confirmed_number', $this->delivery($other, 'safety_check_in', 'sms')->reason);

        // Confirm a number: a code by SMS, then the code.
        $this->actingAs($other)->postJson(route('ehail.comms.sms.start'), ['number' => '082 123 4567'])->assertOk()->assertJsonPath('number', '••••••••4567');
        $this->assertSame('+27821234567', $sms->sent[0]['to']);
        preg_match('/code is (\d{6})/', $sms->sent[0]['message'], $code);
        $this->actingAs($other)->postJson(route('ehail.comms.sms.confirm'), ['code' => '000000'])->assertStatus(422);
        $this->actingAs($other)->postJson(route('ehail.comms.sms.confirm'), ['code' => $code[1]])->assertOk()->assertJsonPath('verified', true);
        $this->assertStringNotContainsString('821234567', (string) \DB::table('notification_sms_numbers')->value('number'), 'encrypted at rest');

        $other->notify(new SafetyUpdateNotification('safety_incident_acknowledged', 'We are on it', 'An operator acknowledged your report.'));
        $this->assertSame(NotificationDelivery::SENT, $this->delivery($other, 'safety_incident_acknowledged', 'sms')->status, (string) $this->delivery($other, 'safety_incident_acknowledged', 'sms')->reason);
        $this->assertStringStartsWith('Dot.Ehail: We are on it.', end($sms->sent)['message']);

        // A non-safety notification never goes by SMS, whatever the person set.
        app(NotificationRouter::class)->send($this->message($other, 'payout_paid', 'payout:6:paid'));
        $this->assertNull($this->delivery($other, 'payout_paid', 'sms'));
        $this->assertCount(2, $sms->sent);
    }

    public function test_dot_notify_owns_email_for_its_events_and_comms_never_sends_a_second_copy(): void
    {
        config(['notify.url' => 'https://notify.test/api/ingest/events', 'notify.secret' => 'secret']);
        $this->deliveringMail();
        Mail::fake();
        Bus::fake([SendNotifyEvent::class]);
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = User::factory()->withPersonalTeam()->create();
        foreach ([$passenger, $driver] as $person) {
            $person->currentTeam->forceFill(['infodot_team_uuid' => (string) \Illuminate\Support\Str::uuid()])->save();
        }
        $ride = Ride::forceCreate(['passenger_id' => $passenger->id, 'driver_id' => $driver->id, 'status' => 'cancelled_by_passenger', 'pickup_address' => 'A', 'dropoff_address' => 'B', 'vehicle_type' => 'standard']);

        app(NotificationRouter::class)->send($this->message($passenger, 'driver_assigned', 'ride:9:driver_assigned:0'));
        $this->assertSame('sent_by_dot_notify', $this->delivery($passenger, 'driver_assigned', 'email')->reason);

        // Trip email is off by default for comms' own mail, but Notify sent these before comms
        // existed: a default does not stop it, and the record says Notify has it.
        $driver->notify(new RideNoticeNotification($ride, 'ride_cancelled', 'Passenger cancelled', 'Ride cancelled.', 'passenger', 'Changed plans'));
        $this->assertSame('sent_by_dot_notify', $this->delivery($driver, 'ride_cancelled', 'email')->reason);
        Mail::assertNothingSent();
        Bus::assertDispatchedTimes(SendNotifyEvent::class, 1);

        // A notification Notify does not carry is emailed by comms.
        $this->actingAs($passenger)->putJson(route('ehail.comms.preferences.update'), ['choices' => [['category' => 'trip', 'channel' => 'email', 'enabled' => true]]])->assertOk();
        $passenger->notify(new RideCompletedNotification($ride));
        Mail::assertSent(CommsMail::class, 1);

        // Explicitly off: Dot.Notify is not asked to email either, and the record says so.
        $this->actingAs($driver)->putJson(route('ehail.comms.preferences.update'), ['choices' => [['category' => 'trip', 'channel' => 'email', 'enabled' => false]]])->assertOk();
        $driver->notify(new RideNoticeNotification($ride, 'ride_cancelled', 'Passenger cancelled', 'Second.', 'passenger', 'Changed plans'));
        Bus::assertDispatchedTimes(SendNotifyEvent::class, 1);
        $this->assertSame(['preference_off', 'sent_by_dot_notify'], $this->deliveries($driver, 'ride_cancelled')->where('channel', 'email')->pluck('reason')->sort()->values()->all());
    }

    private function fakeSms(): object
    {
        $fake = new class implements SmsGateway
        {
            /** @var list<array{to: string, message: string}> */
            public array $sent = [];

            public function isConfigured(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'fake';
            }

            public function send(string $to, string $message): SmsResult
            {
                $this->sent[] = ['to' => $to, 'message' => $message];

                return SmsResult::sent('sms-'.count($this->sent));
            }
        };
        $this->app->instance(SmsGateway::class, $fake);
        $this->app->forgetInstance(\App\Domain\Comms\ChannelStatus::class);
        $this->app->forgetInstance(\App\Domain\Comms\DeliveryWorker::class);

        return $fake;
    }
}
