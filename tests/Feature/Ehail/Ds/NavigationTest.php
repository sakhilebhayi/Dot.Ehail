<?php

namespace Tests\Feature\Ehail\Ds;

use App\Models\User;
use App\Support\Ui\Icons;
use App\Support\Ui\Navigation;
use App\Support\Ui\RoleHome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    public function test_a_wave_two_route_replaces_its_fallback_as_soon_as_it_exists(): void
    {
        $user = User::factory()->create();
        $navigation = app(Navigation::class);

        // The passenger app (R-46 passenger, D-800) registered the wave-2 names, which replaced their fallbacks.
        $passenger = collect($navigation->items('passenger', $user, Request::create('/ride')))->keyBy('key');
        $this->assertSame('ride.trips', $passenger['trips']['route']);
        $this->assertSame('ride.safety', $passenger['safety']['route']);
        $this->assertSame('ride.account', $passenger['account']['route']);
    }

    public function test_role_requirements_hide_destinations(): void
    {
        $navigation = app(Navigation::class);
        $this->assertSame([], $navigation->items('ops', User::factory()->create()));
        $this->assertNotEmpty($navigation->items('ops', $this->makeOperator()));
    }

    public function test_primary_surface_follows_the_strongest_role(): void
    {
        $roles = new RoleHome;
        $this->assertSame('passenger', $roles->primarySurface(User::factory()->create()));
        $this->assertSame('driver', $roles->primarySurface($this->makeApprovedDriver()));
        $this->assertSame('ops', $roles->primarySurface($this->makeOperator()));
        $this->assertSame(['driver', 'passenger'], $roles->surfaces($this->makeApprovedDriver()));
    }

    public function test_every_navigation_icon_exists_in_the_icon_set(): void
    {
        foreach (Navigation::SURFACES as $surface => $items) {
            foreach ($items as $item) {
                $this->assertTrue(Icons::has($item['icon']), "{$surface}.{$item['key']} uses a missing icon {$item['icon']}");
            }
        }
        $this->assertGreaterThanOrEqual(60, count(Icons::names()));
    }
}
