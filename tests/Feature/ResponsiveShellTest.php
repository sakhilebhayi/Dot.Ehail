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
            [$this->makeOperator(), route('rides.index')],
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
        // The operator desk moved into the operations centre (R-46 ops); the legacy drawer still links to its old
        // addresses, which redirect there.
        $xpath = $this->assertResponsiveShell($this->actingAs($this->makeOperator())->get(route('rides.index')));

        foreach (['operator.rides.index', 'operator.fleets.index'] as $name) {
            $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//a[@href="'.route($name).'"]')->length, $name);
        }
    }

    public function test_sign_out_and_the_team_switch_are_in_the_header_account_menu_not_a_second_copy_in_the_drawer(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $second = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false, 'name' => 'Cape Cabs Operations Team']);

        $xpath = $this->assertResponsiveShell($this->actingAs($user)->get(route('dashboard')));

        $menu = '//header[contains(concat(" ", normalize-space(@class), " "), " dh ")]//div[contains(@class, "dh-account")]';
        $this->assertSame(1, $xpath->query($menu.'//form[@action="'.route('logout').'"]//button[@type="submit"]')->length);
        $this->assertSame(1, $xpath->query($menu.'//a[@href="'.route('teams.show', $user->currentTeam).'"]')->length);
        $this->assertSame(2, $xpath->query($menu.'//form[@action="'.route('current-team.update').'"]//input[@name="team_id"]')->length);
        $this->assertSame(1, $xpath->query($menu.'//input[@name="team_id"][@value="'.$second->id.'"]')->length);
        $this->assertSame(0, $xpath->query('//aside[@id="app-sidebar"]//form[@action="'.route('logout').'"]')->length, 'the sidebar no longer repeats the sign-out');
    }

    public function test_the_legacy_notification_bell_is_dot_uis_panel_bell_and_loads_its_list_on_first_open(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($user)->test(NotificationBell::class)
            ->assertSeeHtml('data-dh-bell')
            ->assertSeeHtml('aria-label="Notifications"')
            ->assertSet('loaded', false)
            ->assertSee('Loading')
            ->call('load')
            ->assertSet('loaded', true)
            ->assertSee('No notifications yet.');
    }
}
