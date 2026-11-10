<?php

namespace Tests\Feature\Pricing;

use App\Domain\Pricing\Models\CommissionPlan;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Domain\Pricing\Quotes\FareQuoter;
use App\Domain\Pricing\Quotes\QuoteRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PricingFixtures;
use Tests\TestCase;

class ExampleTariffTest extends TestCase
{
    use PricingFixtures, RefreshDatabase;

    public function test_install_command_publishes_labelled_example_tariffs_idempotently(): void
    {
        $this->artisan('ehail:pricing:install-example-tariff')->assertSuccessful();
        $this->artisan('ehail:pricing:install-example-tariff')->assertSuccessful();

        $this->assertSame(8, PricingRuleSet::count(), 'one per priced category, installed once');
        $this->assertSame(1, CommissionPlan::count());
        $this->assertSame(0, PricingRuleSet::where('is_example', false)->count());
        $this->assertTrue(PricingRuleSet::all()->every(fn ($r) => str_starts_with($r->name, 'Example tariff: review before launch')));
        $this->assertSame(0, PricingRuleSet::whereNotNull('zone_fees')->count(), 'no invented airport fee');
        $this->assertSame('none', PricingRuleSet::first()->tax_mode, 'VAT off until the accountant confirms');
    }

    public function test_install_command_refuses_in_production_without_force(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('ehail:pricing:install-example-tariff')->assertFailed();
        $this->assertSame(0, PricingRuleSet::count());
    }

    public function test_example_quotes_say_they_are_examples(): void
    {
        $this->artisan('ehail:pricing:install-example-tariff');
        $this->fakeRoute(12_000, 1_200);

        $option = $this->app->make(FareQuoter::class)->estimate(new QuoteRequest($this->capeTown(), $this->seaPoint(), 'economy'));

        $this->assertTrue($option->toArray()['tariff']['is_example']);
        // R10 + 12 km x R7.50 + 20 min x R0.70 + R3 booking = R117, whole-rand rounding
        $this->assertSame(11_700, $option->breakdown->totalMinor());
    }

    public function test_prune_command_deletes_only_old_unused_quotes(): void
    {
        $this->artisan('ehail:pricing:install-example-tariff');
        $this->fakeRoute();
        $passenger = User::factory()->create();
        $this->app->make(FareQuoter::class)->quote(new QuoteRequest($this->capeTown(), $this->seaPoint(), 'economy', $passenger));

        $this->artisan('ehail:pricing:prune-quotes')->assertSuccessful();
        $this->assertSame(1, FareQuote::count());

        $this->travel(25)->hours();
        $this->artisan('ehail:pricing:prune-quotes')->assertSuccessful();
        $this->assertSame(0, FareQuote::count());
    }
}
