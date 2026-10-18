<?php

use App\Console\Commands\PublishMetrics;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs the host's one-minute cron entry: * * * * * php artisan schedule:run
// withoutOverlapping() keeps a slow run from notifying the same passenger twice.
Schedule::command('ehail:expire-rides')->everyMinute()->withoutOverlapping();

// Signed aggregate snapshots to Dot.Analytics just after the UTC day turns over (taken_at is the
// start of that UTC day, so a re-run the same day is the same snapshot upstream). Does nothing
// until ANALYTICS_INGEST_URL and ANALYTICS_INGEST_SECRET are set; needs the same one-minute
// scheduler cron as above and a queue worker.
Schedule::command(PublishMetrics::class)->dailyAt('00:15')->timezone('UTC')->withoutOverlapping();
