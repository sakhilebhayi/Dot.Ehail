<?php

namespace App\Domain\Pricing\Console;

use App\Domain\Pricing\Admin\ExampleTariff;
use Illuminate\Console\Command;

class InstallExampleTariffCommand extends Command
{
    protected $signature = 'ehail:pricing:install-example-tariff {--force : Allow it in production (the prices are placeholders the owner must review)}';

    protected $description = 'Publish the EXAMPLE South African tariff and commission plan (placeholders, labelled "review before launch").';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing in production without --force: these are placeholder prices, labelled "Example tariff: review before launch". Publish owner-approved tariffs through the pricing admin instead.');

            return self::FAILURE;
        }

        $created = ExampleTariff::install();
        $this->info("Example tariff installed: {$created['rule_sets']} rule set(s), {$created['commission_plans']} commission plan(s) created (existing ones left untouched).");
        $this->warn('These prices are placeholders. Review and replace them before launch.');

        return self::SUCCESS;
    }
}
