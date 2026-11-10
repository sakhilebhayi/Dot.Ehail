<?php

use App\Domain\Catalog\RideCategoryCatalogue;
use Illuminate\Database\Migrations\Migration;

/**
 * Ride categories are reference data every environment needs (production deploys run migrations, not seeders).
 * Prices are NOT installed here: a fresh production has no published tariff until an operator publishes one
 * (or runs `php artisan ehail:pricing:install-example-tariff` knowingly). See docs/R46_PRICING.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        RideCategoryCatalogue::install();
    }

    public function down(): void
    {
        // Reference rows are removed with their table by the previous migration's down().
    }
};
