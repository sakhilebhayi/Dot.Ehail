<?php

namespace Database\Seeders;

use App\Domain\Geo\Zones\ReferenceAreas;
use Illuminate\Database\Seeder;

/**
 * Reference (not demo) data: the approximate South African service areas and airport zones. Safe in production
 * because it only creates missing rows and every row is flagged is_approximate for operator review.
 */
class GeoReferenceAreasSeeder extends Seeder
{
    public function run(): void
    {
        ReferenceAreas::install();
    }
}
