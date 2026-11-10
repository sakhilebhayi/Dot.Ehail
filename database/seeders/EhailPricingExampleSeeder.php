<?php

namespace Database\Seeders;

use App\Domain\Pricing\Admin\ExampleTariff;
use Illuminate\Database\Seeder;

/**
 * Development/acceptance seeding of the EXAMPLE tariff (labelled "review before launch"). Refuses in production:
 * there, an operator publishes real prices or runs the install command knowingly with --force.
 */
class EhailPricingExampleSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('EhailPricingExampleSeeder skipped in production (placeholder prices).');

            return;
        }

        ExampleTariff::install();
    }
}
