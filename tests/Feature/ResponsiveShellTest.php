<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssertsResponsiveShell;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class ResponsiveShellTest extends TestCase
{
    use AssertsResponsiveShell, CreatesEhailFixtures, RefreshDatabase;

    public function test_every_signed_in_page_carries_the_drawer_hooks(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide([], $passenger);

        foreach ([
            [$passenger, route('dashboard')],
            [$passenger, route('rides.index')],
            [$passenger, route('rides.create')],
            [$passenger, route('rides.show', $ride)],
            [$passenger, route('profile.show')],
            [$driver, route('rides.available')],
            [$driver, route('drivers.vehicles.index')],
            [$this->makeOperator(), route('operator.rides.index')],
        ] as [$user, $url]) {
            $this->assertResponsiveShell($this->actingAs($user)->get($url));
        }
    }

    public function test_the_legacy_jetstream_bar_is_wrapped_so_phones_can_hide_it(): void
    {
        $xpath = $this->assertResponsiveShell($this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('dashboard')));

        $this->assertSame(1, $xpath->query('//div[contains(@class, "shell-legacy-nav")]/nav')->length);
    }

    public function test_the_ride_tables_sit_in_labelled_horizontal_scroll_regions(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $this->makeRide(['pickup_address' => 'Scroll region pickup at Rondebosch Common'], $passenger);

        $this->assertScrollsHorizontally($this->assertResponsiveShell($this->actingAs($passenger)->get(route('dashboard'))), 'Recent rides');
        $this->assertScrollsHorizontally($this->assertResponsiveShell($this->actingAs($passenger)->get(route('rides.index'))), 'Rides');
    }

    public function test_the_drawer_reaches_the_ride_request_page_that_only_the_hidden_jetstream_bar_linked(): void
    {
        $xpath = $this->assertResponsiveShell($this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('dashboard')));

        $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route('rides.create').'"]')->length);
        $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route('drive.apply').'"]')->length);
        $this->assertSame(0, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route('rides.available').'"]')->length);
        $this->assertSame(0, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route('operator.fleets.index').'"]')->length);
    }

    public function test_a_driver_sees_the_driver_desk_in_the_drawer(): void
    {
        $driver = $this->makeApprovedDriver();
        $xpath = $this->assertResponsiveShell($this->actingAs($driver)->get(route('rides.available')));

        foreach (['rides.available', 'drivers.vehicles.index'] as $name) {
            $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route($name).'"]')->length, $name);
        }
        $this->assertSame(0, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route('drive.apply').'"]')->length);
    }

    public function test_an_operator_sees_the_operator_links_in_the_drawer(): void
    {
        $xpath = $this->assertResponsiveShell($this->actingAs($this->makeOperator())->get(route('operator.rides.index')));

        foreach (['operator.rides.index', 'operator.fleets.index'] as $name) {
            $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route($name).'"]')->length, $name);
        }
    }

    public function test_the_drawer_footer_has_sign_out_and_a_team_switcher(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $second = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false, 'name' => 'Cape Cabs Operations Team']);

        $xpath = $this->assertResponsiveShell($this->actingAs($user)->get(route('dashboard')));

        $footer = '//aside[@id="app-sidebar"]//div[contains(@class, "sidebar-footer")]';
        $this->assertSame(1, $xpath->query($footer.'//form[@action="'.route('logout').'"]//button[@aria-label="Sign out"]')->length);
        $this->assertSame(1, $xpath->query($footer.'//details[contains(@class, "team-switch")]/summary')->length);
        $this->assertSame(1, $xpath->query($footer.'//a[@href="'.route('teams.show', $user->currentTeam).'"]')->length);
        $this->assertSame(2, $xpath->query($footer.'//form[@action="'.route('current-team.update').'"]//input[@name="team_id"]')->length);
        $this->assertSame(1, $xpath->query($footer.'//input[@name="team_id"][@value="'.$second->id.'"]')->length);
    }

    public function test_the_notification_panel_is_a_class_the_phone_layout_can_pin_inside_the_viewport(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($user)->test(NotificationBell::class)
            ->assertDontSeeHtml('notif-panel')
            ->assertSeeHtml('aria-label="Notifications"')
            ->set('open', true)
            ->assertSeeHtml('class="notif-panel"');
    }
}
