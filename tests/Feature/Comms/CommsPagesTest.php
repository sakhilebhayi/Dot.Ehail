<?php

namespace Tests\Feature\Comms;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\NotificationRouter;
use App\Domain\Comms\Preferences;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCommsFixtures;
use Tests\TestCase;

class CommsPagesTest extends TestCase
{
    use CreatesCommsFixtures, RefreshDatabase;

    public function test_the_passenger_inbox_shows_real_items_and_marks_them_read(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        app(NotificationRouter::class)->send(new CommsMessage($user, 'driver_arrived', 'ride:1:driver_arrived:0', 'Your driver has arrived', 'Thabo is waiting at the pickup point.', '/rides/1', 'ride-1'));

        $this->actingAs($user)->get(route('ride.notifications'))->assertOk()
            ->assertSee('Notifications')->assertSee('1 unread.')
            ->assertSee('Your driver has arrived')->assertSee('Thabo is waiting at the pickup point.')
            ->assertSee('Trip updates')
            ->assertSee('Email: off in your settings');

        $id = $user->notifications()->first()->id;
        $this->actingAs($user)->post(route('ride.notifications.read', $id), ['open' => 1])->assertRedirect('/rides/1');
        $this->assertNotNull($user->notifications()->first()->read_at);
        $this->actingAs($user)->get(route('ride.notifications', ['show' => 'unread']))->assertOk()->assertSee('Nothing unread');
    }

    public function test_an_empty_inbox_says_so_in_each_shell(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user)->get(route('ride.notifications'))->assertOk()->assertSee('No notifications yet')->assertSee('Choose how we reach you');
        $this->actingAs($user)->get(route('drive.notifications'))->assertOk()->assertSee('Ride offers, trip updates, payouts');
        $this->actingAs($user)->get(route('ops.notifications'))->assertForbidden();

        $user->forceFill(['is_platform_operator' => true])->save();
        $this->actingAs($user)->get(route('ops.notifications'))->assertOk()->assertSee('Safety alerts, driver applications');
    }

    public function test_settings_page_states_each_channel_honestly(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get(route('ride.notifications.settings'))->assertOk()
            ->assertSee('How we can reach you')
            ->assertSee('Push notifications are not set up on this service yet')
            ->assertSee('Email is not being delivered by this service right now')
            ->assertSee('SMS is not available on this service')
            ->assertSee('Safety messages cannot be switched off')
            ->assertSee('Scheduled rides')
            ->assertDontSee('Payouts and incentives')
            ->assertDontSee('SMS for safety');

        $this->configurePush();
        $this->deliveringMail();
        $this->actingAs($user)->get(route('drive.notifications.settings'))->assertOk()
            ->assertSee('Turn on for this device')
            ->assertSee('Emails go to your sign-in address.')
            ->assertSee('Payouts and incentives')
            ->assertSee('In the app only');
    }

    public function test_the_settings_form_saves_without_script_and_cannot_switch_safety_off(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->put(route('ride.notifications.settings.save'), [
            'prefs' => ['trip' => ['email' => '1'], 'payments' => ['push' => '1']],
            'locked' => ['safety' => ['push' => '0']],
        ])->assertRedirect(route('ride.notifications.settings'))->assertSessionHas('status', 'Notification settings saved.');

        $prefs = app(Preferences::class);
        $this->assertTrue($prefs->allows($user, 'trip', 'email'));
        $this->assertFalse($prefs->allows($user, 'trip', 'push'), 'absent box = off');
        $this->assertFalse($prefs->allows($user, 'payments', 'email'));
        $this->assertTrue($prefs->allows($user, 'safety', 'push'));
        $this->assertTrue($prefs->allows($user, 'earnings', 'push'), 'categories not on this surface are untouched');
    }

    public function test_the_role_redirects_and_the_bell(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($passenger)->get(route('comms.inbox'))->assertRedirect(route('ride.notifications'));
        $this->actingAs($passenger)->get(route('comms.settings'))->assertRedirect(route('ride.notifications.settings'));

        $driver = User::factory()->withPersonalTeam()->create();
        DriverProfile::factory()->for($driver)->create(['status' => 'approved']);
        $this->actingAs($driver)->get(route('comms.inbox'))->assertRedirect(route('drive.notifications'));

        app(NotificationRouter::class)->send(new CommsMessage($passenger, 'payout_paid', 'payout:1:paid', 'Payout paid', 'x'));
        $html = $this->actingAs($passenger)->blade('<x-comms::bell surface="passenger" />');
        $html->assertSee('Notifications, 1 unread')->assertSee(route('ride.notifications'));
    }
}
