<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs the host's one-minute cron entry: * * * * * php artisan schedule:run
// withoutOverlapping() keeps a slow run from notifying the same passenger twice.
Schedule::command('ehail:expire-rides')->everyMinute()->withoutOverlapping();
