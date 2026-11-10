<?php

namespace Tests\Feature\Comms;

use App\Domain\Comms\CommsCategories;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Earnings\Models\IncentiveProgram;
use App\Domain\Earnings\Models\IncentiveProgress;
use App\Domain\Earnings\Models\Payout;
use App\Domain\Earnings\Models\PayoutBatch;
use App\Domain\Payments\Models\Receipt;
use App\Domain\Payments\Receipts\ReceiptIssuedNotification;
use App\Domain\Rides\Events\ScheduledRideReminderDue;
use App\Domain\Rides\Events\ScheduledRideRequoteRequired;
use App\Domain\Rides\RideStateMachine;
use App\Domain\Rides\TransitionOptions;
use App\Domain\Safety\Notifications\SafetyUpdateNotification;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\RideNoticeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCommsFixtures;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\TestCase;

/**
 * Domain event -> who is told, what category, which channels. Real dispatch, state machine and
 * models throughout; only the pricing seam is the dispatch suite's test double.
 */
class NotificationMatrixTest extends TestCase
{
    use CreatesCommsFixtures, CreatesDispatchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQuotes();
        config(['ehail_safety.verification.require_documents' => false]);
        Http::fake(['*' => Http::response('', 201)]);
    }

    /** @return array{0: Ride, 1: User, 2: User} */
    private function assigned(): array
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(1500), name: 'Thabo Driver Mokoena');
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Lerato Dlamini']);
        $ride = $this->requestRide($passenger);
        app(Dispatcher::class)->offerResponse(RideOffer::where('pending_ride_id', $ride->id)->firstOrFail(), $driver, 'accept');

        return [$ride->fresh(), $passenger, $driver];
    }

    public function test_every_mapped_type_has_a_real_category(): void
    {
        $categories = app(CommsCategories::class);

        foreach ((array) config('ehail_comms.types') as $type => $category) {
            $this->assertTrue($categories->exists($category), "{$type} maps to a missing category {$category}");
            $this->assertSame($category, $categories->categoryFor($type));
        }

        $this->assertSame('general', $categories->categoryFor('something_new'));
        $this->assertTrue($categories->isCritical('safety'));
        $this->assertSame([], $categories->channels('offers'), 'offers are in-app only');
    }

    public function test_assignment_tells_the_passenger_with_first_name_and_car_only(): void
    {
        [$ride, $passenger, $driver] = $this->assigned();

        $item = $this->inboxData($passenger, 'driver_assigned')->sole();
        $this->assertSame('Your driver is on the way', $item['title']);
        $this->assertStringContainsString('Thabo accepted your ride', $item['message']);
        $this->assertStringNotContainsString('Mokoena', $item['message'], 'never the surname');
        $this->assertSame('trip', $item['category']);
        $this->assertSame("ride-{$ride->id}", $item['collapse_key']);
        $this->assertSame('/rides/'.$ride->id, $item['url']);

        $this->assertSame(NotificationDelivery::SENT, $this->delivery($passenger, 'driver_assigned', 'in_app')->status);
        $this->assertSame('preference_off', $this->delivery($passenger, 'driver_assigned', 'email')->reason, 'trip email is off by default');
        $this->assertSame('push_not_configured', $this->delivery($passenger, 'driver_assigned', 'push')->reason);
        $this->assertCount(0, $this->inboxData($driver, 'driver_assigned'), 'the driver is not told about their own acceptance');
    }

    public function test_arrival_and_trip_start_and_completion(): void
    {
        [$ride, $passenger, $driver] = $this->assigned();

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'arriving']))->assertOk();
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'arrived']))->assertOk();
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'start']), ['pin' => $ride->fresh()->pin_encrypted])->assertOk();
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'complete']))->assertOk();

        $types = $this->inboxData($passenger)->pluck('type')->sort()->values()->all();
        $this->assertSame(['driver_arrived', 'driver_assigned', 'ride_completed', 'trip_started'], $types, 'no separate "arriving" notice');

        $this->assertStringContainsString('give the driver your PIN', $this->inboxData($passenger, 'driver_arrived')->sole()['message']);
        $this->assertCount(0, $this->deliveries($passenger, 'trip_started')->where('channel', '!=', 'in_app'), 'trip started stays in the app');

        // The completion notice comes from the existing RideCompletedNotification; comms fans it out.
        $this->assertSame(['offer_received', 'ride_completed'], $this->inboxData($driver)->pluck('type')->sort()->values()->all());
        $this->assertSame(['ride_completed'], $driver->unreadNotifications()->get()->pluck('data.type')->all(), 'the answered offer no longer counts as unread');
        $this->assertNotNull($this->delivery($driver, 'ride_completed', 'in_app'));
        $this->assertSame('own_action', $this->delivery($driver, 'ride_completed', 'push')->reason, 'the driver completed it: no push to them');
    }

    public function test_approaching_is_sent_once_when_the_driver_comes_within_the_threshold(): void
    {
        [$ride, $passenger, $driver] = $this->assigned();

        $this->heartbeat($driver, $this->northOfPickup(900));
        $this->assertCount(0, $this->inboxData($passenger, 'driver_approaching'));

        $this->heartbeat($driver, $this->northOfPickup(420));
        $this->heartbeat($driver, $this->northOfPickup(200));
        $this->heartbeat($driver, $this->northOfPickup(50));

        $item = $this->inboxData($passenger, 'driver_approaching')->sole();
        $this->assertStringContainsString('about 400 m from the pickup point', $item['message']);
    }

    public function test_scheduled_booking_reminder_requote_and_confirmation(): void
    {
        $passenger = $this->passenger();
        $quote = $this->quotes->issue($passenger);
        $rideId = $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), [
            'quote_id' => $quote->id, 'scheduled_for' => now()->addHours(2)->toIso8601String(),
        ])->assertCreated()->json('ride.id');
        $ride = Ride::findOrFail($rideId);

        $booked = $this->inboxData($passenger, 'scheduled_booked')->sole();
        $this->assertSame('Ride booked, awaiting a driver', $booked['title']);
        $this->assertSame('own_action', $this->delivery($passenger, 'scheduled_booked', 'push')->reason);
        $this->assertSame('mail_transport_not_delivering', $this->delivery($passenger, 'scheduled_booked', 'email')->reason);

        event(new ScheduledRideReminderDue($ride->id, null, 'system', now(), 'scheduled_reminder'));
        event(new ScheduledRideReminderDue($ride->id, null, 'system', now(), 'scheduled_reminder'));
        $this->assertCount(1, $this->inboxData($passenger, 'scheduled_reminder'), 'a doubled reminder event is one notification');

        $ride->forceFill(['requote_total_minor' => 10200, 'requote_quote_id' => 'q-new'])->save();
        event(new ScheduledRideRequoteRequired($ride->id, null, 'system', now(), 'outside_price_guarantee'));
        $requote = $this->inboxData($passenger, 'scheduled_requote_required')->sole();
        $this->assertStringContainsString('R102.00', $requote['message']);
        $this->assertStringContainsString('(booked at R85.00)', $requote['message']);

        // Allocation: the assignment of a scheduled ride reads "driver confirmed".
        $driver = $this->onlineDriverAt(...$this->northOfPickup(300));
        $ride->forceFill(['requote_total_minor' => null, 'requote_quote_id' => null, 'requote_required_at' => null])->save();
        $this->travel(106)->minutes();
        $this->heartbeat($driver, $this->northOfPickup(300));
        $this->artisan('ehail:dispatch-tick');
        $offer = RideOffer::where('pending_ride_id', $ride->id)->first();
        $this->assertNotNull($offer);
        app(Dispatcher::class)->offerResponse($offer, $driver, 'accept');

        $this->assertSame('Driver confirmed for your scheduled ride', $this->inboxData($passenger, 'scheduled_driver_confirmed')->sole()['title']);
        $this->assertCount(0, $this->inboxData($passenger, 'driver_assigned'));
    }

    public function test_offers_reach_the_driver_in_app_only_and_replace_the_previous_unread_offer(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(300));
        $this->configurePush();
        $this->subscribe($driver);

        $first = $this->requestRide();
        $this->assertCount(1, $this->inboxData($driver, 'offer_received'));
        $this->assertCount(0, $this->deliveries($driver, 'offer_received')->where('channel', '!=', 'in_app'), 'never pushed or emailed');

        app(Dispatcher::class)->offerResponse(RideOffer::where('pending_ride_id', $first->id)->firstOrFail(), $driver, 'decline', 'too_far');
        $this->artisan('ehail:dispatch-tick');
        $second = $this->requestRide();

        $offers = $this->inboxData($driver, 'offer_received');
        $this->assertCount(1, $offers, 'only the live offer is in the inbox');
        $this->assertSame($second->id, $offers->sole()['ride_id']);
    }

    public function test_source_notifications_from_other_domains_are_fanned_out_once(): void
    {
        $this->configurePush();
        $passenger = $this->passenger();
        $this->subscribe($passenger);
        $ride = $this->requestRide($passenger);

        $passenger->notify(new RideNoticeNotification($ride, 'ride_expired', 'No driver found', 'Ride expired.'));
        $passenger->notify(new SafetyUpdateNotification('safety_incident_acknowledged', 'We are on it', 'An operator acknowledged your report.', ['incident_id' => 9]));

        $expired = $this->delivery($passenger, 'ride_expired', 'push');
        $this->assertSame(NotificationDelivery::SENT, $expired->status, 'accepted by the (faked) push service');
        $this->assertSame('trip', $expired->category);
        $this->assertSame("ride-{$ride->id}", $expired->collapse_key);

        $safety = $this->deliveries($passenger, 'safety_incident_acknowledged')->keyBy('channel');
        $this->assertSame('safety', $safety['push']->category);
        $this->assertSame(NotificationDelivery::SENT, $safety['push']->status, 'critical: sent at once, not left for the next cron minute');
        $this->assertSame('sms_not_configured', $safety['sms']->reason);
        $this->assertSame('mail_transport_not_delivering', $safety['email']->reason);
        $this->assertSame(1, NotificationDelivery::where('user_id', $passenger->id)->where('type', 'ride_expired')->where('channel', 'in_app')->count());
    }

    public function test_receipts_payouts_and_incentives(): void
    {
        $passenger = $this->passenger();
        $driver = $this->onlineDriverAt(...$this->northOfPickup(300));
        $ride = $this->requestRide($passenger);

        $receipt = Receipt::create([
            'number' => 'EHR-00000042', 'kind' => 'ride', 'ride_id' => $ride->id, 'passenger_id' => $passenger->id,
            'currency' => 'ZAR', 'total_minor' => 12350, 'snapshot' => ['kind' => 'ride', 'components' => []], 'issued_at' => now(), 'created_at' => now(),
        ]);
        $passenger->notify(new ReceiptIssuedNotification($receipt));

        $item = $this->inboxData($passenger, 'payment_receipt')->sole();
        $this->assertSame('Payment received', $item['title']);
        $this->assertStringContainsString('R123.50 paid for your trip. Receipt EHR-00000042 is ready.', $item['message']);
        $email = $this->delivery($passenger, 'payment_receipt', 'email');
        $this->assertSame('receipt:'.$receipt->id, $email->event_key, 'the receipt email is recorded against the same event');
        $this->assertSame('mail_transport_not_delivering', $email->reason, 'the array mailer delivers nothing: not "sent"');

        $batch = PayoutBatch::create(['currency' => 'ZAR', 'period_end' => now()->toDateString()]);
        $payout = Payout::create([
            'payout_batch_id' => $batch->id, 'payee_user_id' => $driver->id, 'payee_type' => 'driver', 'currency' => 'ZAR',
            'earnings_minor' => 125000, 'amount_minor' => 125000, 'composition' => [], 'status' => Payout::SCHEDULED, 'reference' => 'PO-TEST-1',
        ]);
        $payout->forceFill(['status' => Payout::FAILED, 'failure_reason' => 'Account closed'])->save();
        $payout->forceFill(['status' => Payout::PAID, 'paid_at' => now()])->save();

        $this->assertStringContainsString('could not be paid: Account closed', $this->inboxData($driver, 'payout_failed')->sole()['message']);
        $this->assertStringContainsString("R1\u{202F}250.00 (reference PO-TEST-1) has been paid out", $this->inboxData($driver, 'payout_paid')->sole()['message']);
        $this->assertSame('earnings', $this->delivery($driver, 'payout_paid', 'in_app')->category);

        $program = IncentiveProgram::create(['name' => 'Weekend 20 trips', 'kind' => 'trip_count', 'rules' => [], 'reward_minor' => 20000, 'currency' => 'ZAR', 'starts_at' => now()->subDay(), 'status' => 'active']);
        $progress = IncentiveProgress::create(['incentive_program_id' => $program->id, 'driver_id' => $driver->id, 'window_start' => now()->subDay(), 'progress' => [], 'status' => 'in_progress']);
        $progress->forceFill(['status' => 'earned', 'earned_at' => now()])->save();
        $progress->forceFill(['evaluated_at' => now()])->save();

        $this->assertStringContainsString('You earned R200.00 from "Weekend 20 trips"', $this->inboxData($driver, 'incentive_earned')->sole()['message']);
    }

    public function test_a_rolled_back_transition_notifies_nobody(): void
    {
        [$ride, $passenger, $driver] = $this->assigned();
        $before = $passenger->notifications()->count();

        try {
            \DB::transaction(function () use ($ride, $driver) {
                app(RideStateMachine::class)->transition($ride, RideStatus::DriverArrived, RideActor::Driver, $driver, new TransitionOptions);
                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, $passenger->notifications()->count());
    }
}
