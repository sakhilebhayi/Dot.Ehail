<?php

namespace Tests\Feature\Deploy;

use App\Domain\Pricing\Models\PricingRuleSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A bare `php artisan db:seed` on the live host must not create a user with a known password or placeholder prices.
 */
class ProductionSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_seeder_refuses_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
        $this->assertSame(0, PricingRuleSet::query()->count());
    }

    public function test_it_still_seeds_a_development_database(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        $this->assertTrue(User::query()->where('email', 'test@example.com')->exists());
        $this->assertGreaterThan(0, PricingRuleSet::query()->where('is_example', true)->count());
    }
}
