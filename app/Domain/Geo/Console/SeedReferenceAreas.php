<?php

namespace App\Domain\Geo\Console;

use App\Domain\Geo\Zones\ReferenceAreas;
use Illuminate\Console\Command;

class SeedReferenceAreas extends Command
{
    protected $signature = 'ehail:geo-reference-areas';

    protected $description = 'Create the approximate South African service areas and airport zones that are missing (never overwrites edits)';

    public function handle(): int
    {
        $result = ReferenceAreas::install();

        $this->info(sprintf('%d service areas and %d zones created. All are flagged approximate: review them before launch.', $result['areas_created'], $result['zones_created']));

        return self::SUCCESS;
    }
}
