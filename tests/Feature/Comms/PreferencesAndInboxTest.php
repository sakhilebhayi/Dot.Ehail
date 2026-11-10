<?php

namespace Tests\Feature\Comms;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\NotificationRouter;
use App\Domain\Comms\Preferences;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCommsFixtures;
use Tests\TestCase;

class PreferencesAndInboxTest extends TestCase
{
    use CreatesCommsFixtures, RefreshDatabase;

    private function notify(User $user, string $type, string $key, string $title = 'Payout paid', ?string $url = '/money/driver/payouts/1'): void
    {
        app(NotificationRouter::class)->send(new CommsMessage($user, $type, $key, $title, 'Body text.', $url));
    }

    public function test_defaults_then_changes_then_the_change_is_applied(): void
    {
        $this->configurePush();
        $user = User::factory()->create();
        $this->subscribe($user);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 201)]);

        $this->actingAs($user)->getJson(route('ehail.comms.preferences', ['surface' => 'driver']))->assertOk()
            ->assertJsonPath('categories.earnings.channels.push.enabled', true)
            ->assertJsonPath('categories.trip.channels.email.enabled', false)
            ->assertJsonPath('categories.offers.channels', [])
            ->assertJsonPath('categories.safety.critical', true)
            ->assertJsonPath('categories.safety.channels.push.locked', true)
            ->assertJsonMissingPath('categories.scheduled')
            ->assertJsonPath('push.configured', true)
            ->assertJsonPath('push.devices.0.label', 'Chrome on Android')
            ->assertJsonPath('sms.configured', false);

        $this->actingAs($user)->putJson(route('ehail.comms.preferences.update'), ['choices' => [['category' => 'earnings', 'channel' => 'push', 'enabled' => false]]])
            ->assertOk()->assertJsonPath('categories.earnings.channels.push.enabled', false);

        $this->notify($user, 'payout_paid', 'payout:1:paid');
        $this->assertSame('preference_off', $this->delivery($user, 'payout_paid', 'push')->reason);
        $this->assertSame(1, $user->notifications()->count(), 'in-app is always on');
    }

    public function test_safety_cannot_be_switched_off_and_unknown_choices_are_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson(route('ehail.comms.preferences.update'), ['choices' => [['category' => 'safety', 'channel' => 'push', 'enabled' => false]]])
            ->assertStatus(422)->assertJsonPath('error', 'locked');
        $this->actingAs($user)->putJson(route('ehail.comms.preferences.update'), ['choices' => [['category' => 'offers', 'channel' => 'push', 'enabled' => true]]])
            ->assertStatus(422)->assertJsonPath('error', 'unknown_choice');
        $this->actingAs($user)->putJson(route('ehail.comms.preferences.update'), ['choices' => [['category' => 'trip', 'channel' => 'fax', 'enabled' => true]]])
            ->assertStatus(422);

        $this->assertTrue(app(Preferences::class)->allows($user, 'safety', 'push'));
        $this->assertTrue(app(Preferences::class)->allows($user, 'safety', 'email'));
    }

    public function test_the_inbox_lists_only_your_own_real_items_with_their_delivery_outcomes(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $this->notify($user, 'payout_paid', 'payout:1:paid');
        $this->notify($user, 'payout_failed', 'payout:2:failed', 'Payout failed', 'https://evil.example.com/phish');
        $this->notify($stranger, 'payout_paid', 'payout:3:paid', 'Stranger payout');

        $response = $this->actingAs($user)->getJson(route('ehail.comms.inbox'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('unread_count', 2);

        $items = collect($response->json('items'))->keyBy('type');
        $this->assertSame('Payouts and incentives', $items['payout_paid']['category_label']);
        $this->assertSame('/money/driver/payouts/1', $items['payout_paid']['url']);
        $this->assertNull($items['payout_failed']['url'], 'a link off this site is never offered');
        $this->assertContains('Email: not delivered (this service only logs email)', collect($items['payout_paid']['deliveries'])->pluck('label')->all());
        $this->assertNotContains('Stranger payout', collect($response->json('items'))->pluck('title')->all());

        $strangerItem = $stranger->notifications()->first()->id;
        $this->actingAs($user)->postJson(route('ehail.comms.inbox.read', $strangerItem))->assertNotFound();
        $this->assertNull($stranger->notifications()->first()->read_at);

        $first = $user->notifications()->first()->id;
        $this->actingAs($user)->postJson(route('ehail.comms.inbox.read', $first))->assertOk()->assertJsonPath('unread_count', 1);
        $this->actingAs($user)->getJson(route('ehail.comms.inbox', ['unread' => 1]))->assertJsonCount(1, 'items');
        $this->actingAs($user)->postJson(route('ehail.comms.inbox.read-all'))->assertOk()->assertJsonPath('unread_count', 0);
        $this->actingAs($user)->getJson(route('ehail.comms.inbox.summary'))->assertJsonPath('unread_count', 0);
    }

    public function test_an_empty_inbox_is_empty_and_paging_works(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson(route('ehail.comms.inbox'))->assertOk()->assertJsonPath('items', [])->assertJsonPath('next_cursor', null);

        foreach (range(1, 25) as $i) {
            $this->notify($user, 'payout_paid', "payout:{$i}:paid");
        }

        $page = $this->actingAs($user)->getJson(route('ehail.comms.inbox'))->assertJsonCount(20, 'items');
        $this->actingAs($user)->getJson(route('ehail.comms.inbox', ['cursor' => $page->json('next_cursor')]))->assertJsonCount(5, 'items');
    }

    public function test_the_api_needs_a_signed_in_person(): void
    {
        $this->getJson(route('ehail.comms.inbox'))->assertUnauthorized();
        $this->putJson(route('ehail.comms.preferences.update'), [])->assertUnauthorized();
        $this->postJson(route('ehail.comms.push.subscribe'), [])->assertUnauthorized();
    }

    public function test_the_prune_command_keeps_recent_records(): void
    {
        $user = User::factory()->create();
        $this->notify($user, 'payout_paid', 'payout:1:paid');
        NotificationDelivery::query()->update(['created_at' => now()->subDays(91)]);
        $this->notify($user, 'payout_paid', 'payout:2:paid');

        $this->artisan('ehail:comms-prune')->assertSuccessful();

        $this->assertSame(['payout:2:paid'], NotificationDelivery::pluck('event_key')->unique()->values()->all());
    }

    public function test_vapid_key_command_prints_a_usable_pair(): void
    {
        $this->artisan('ehail:comms-vapid-keys')->expectsOutputToContain('EHAIL_VAPID_PUBLIC_KEY=')->assertSuccessful();
    }
}
