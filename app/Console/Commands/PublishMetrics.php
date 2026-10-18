<?php

namespace App\Console\Commands;

use App\Jobs\SendAnalyticsSnapshot;
use App\Services\Analytics\AnalyticsClient;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes today's daily aggregate snapshot to Dot.Analytics, one queued job per company.
 *
 * Scheduled once a day just after 00:00 UTC (routes/console.php). taken_at is the start of the
 * current UTC day, so running it again the same day (a manual run, a retried cron) re-sends the
 * same snapshot identity, which Analytics stores once. Does nothing at all until
 * ANALYTICS_INGEST_URL and ANALYTICS_INGEST_SECRET are both set, and a failure to collect or to
 * queue is logged and swallowed per company: it never touches the app's own data or requests.
 */
class PublishMetrics extends Command
{
    protected $signature = 'ehail:publish-metrics
        {--company= : Only this InfoDot company uuid}
        {--dry-run : Print what would be sent; collect only, queue and send nothing}';

    protected $description = 'Publish the daily aggregate metrics snapshot to Dot.Analytics (aggregates only)';

    public function handle(MetricsCollector $collector, AnalyticsClient $client): int
    {
        $only = $this->option('company');
        $dryRun = (bool) $this->option('dry-run');

        if ($only !== null && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $only)) {
            $this->error('--company must be an InfoDot company uuid.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $client->enabled()) {
            $this->warn('Dot.Analytics publishing is off: set ANALYTICS_INGEST_URL and ANALYTICS_INGEST_SECRET. Nothing was collected or sent.');

            return self::SUCCESS;
        }

        $takenAt = Carbon::now('UTC')->startOfDay();
        $day = $takenAt->format('Y-m-d\TH:i:s\Z');

        try {
            $snapshots = $collector->snapshots($takenAt, $only === null ? null : strtolower((string) $only));
        } catch (Throwable $e) {
            Log::error('Could not collect the Dot.Analytics snapshot.', ['error' => class_basename($e)]);
            $this->error('Could not collect the metrics (see the log). Nothing was sent.');

            return self::FAILURE;
        }

        if ($snapshots === []) {
            $this->info('No company has anything to publish'.($only ? ' for that uuid.' : '.'));

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($snapshots as $uuid => $metrics) {
            $payload = $client->payload((string) $uuid, $takenAt, $metrics);

            if ($dryRun) {
                $this->line($uuid.' '.json_encode($payload['metrics'], JSON_UNESCAPED_SLASHES));

                continue;
            }

            try {
                app(Dispatcher::class)->dispatch(new SendAnalyticsSnapshot($payload));
                $queued++;
            } catch (Throwable $e) {
                Log::warning('Could not queue a Dot.Analytics snapshot.', ['company_uuid' => $uuid, 'error' => class_basename($e)]);
            }
        }

        $this->info($dryRun
            ? 'Dry run: '.count($snapshots).' snapshot(s) for '.$day.' would be sent. Nothing was queued.'
            : "Queued {$queued} of ".count($snapshots)." snapshot(s) for {$day}.");

        return self::SUCCESS;
    }
}
