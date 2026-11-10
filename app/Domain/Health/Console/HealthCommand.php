<?php

namespace App\Domain\Health\Console;

use App\Domain\Health\HealthAlerts;
use App\Domain\Health\HealthReport;
use Illuminate\Console\Command;

/**
 * php artisan ehail:health [--json] [--alert]
 *
 * Prints every operational check (docs/R46_PERF.md section 6). Exit code 0 when ok, 1 when degraded, 2 when failing,
 * so an external monitor can run it. With --alert (scheduled every five minutes) it emails the ops address
 * (EHAIL_HEALTH_ALERT_EMAIL) when a check gets worse, at most once per check per alert_every_minutes.
 */
class HealthCommand extends Command
{
    protected $signature = 'ehail:health {--json : Print the report as JSON} {--alert : Email the ops address when a check gets worse}';

    protected $description = 'Dot.Ehail operational health: database, scheduler, dispatch tick, queue lag, failed jobs, silent drivers, payment webhooks, notifications, routing and geocoding circuits.';

    public function handle(HealthReport $report, HealthAlerts $alerts): int
    {
        $result = $report->run();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line("Dot.Ehail health: {$result['status']} (real-time mode: {$result['mode']})");
            $this->table(['Check', 'Status', 'Summary'], collect($result['checks'])->map(fn (array $c, string $name) => [$name, $c['status'], $c['summary']])->values()->all());
        }

        if ($this->option('alert')) {
            $sent = $alerts->notify($result);

            if ($sent !== null) {
                $this->line($sent);
            }
        }

        return match ($result['status']) {
            HealthReport::FAILING => 2,
            HealthReport::DEGRADED => 1,
            default => self::SUCCESS,
        };
    }
}
