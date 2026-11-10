<?php

namespace App\Domain\Health;

use App\Domain\Health\Mail\HealthAlertMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email to the operations address when a health check gets worse (docs/R46_PERF.md section 6). Quiet by design:
 * one email per check per alert_every_minutes while it stays bad, and one "recovered" line when it is ok again.
 * With no address configured, nothing is sent and the command says so (no pretend alerting). Mail that is
 * configured but only logs (MAIL_MAILER=log or array) is reported as not delivered.
 */
class HealthAlerts
{
    private const STATE_KEY = 'ehail:health:alert-state';

    /**
     * @param  array{status: string, checks: array<string, array{status: string, summary: string}>}  $report
     * @return string|null what happened, for the command output
     */
    public function notify(array $report): ?string
    {
        $address = (string) config('ehail_perf.health.alert_email');
        $previous = Cache::get(self::STATE_KEY, []);
        $every = max(1, (int) config('ehail_perf.health.alert_every_minutes', 30)) * 60;
        $now = now()->getTimestamp();
        $state = [];
        $lines = [];

        foreach ($report['checks'] as $name => $check) {
            $bad = in_array($check['status'], [HealthReport::DEGRADED, HealthReport::FAILING], true);
            $before = $previous[$name] ?? ['status' => HealthReport::OK, 'alerted_at' => 0];
            $state[$name] = ['status' => $check['status'], 'alerted_at' => $before['alerted_at']];

            if ($bad && (self::rank($check['status']) > self::rank($before['status']) || $now - (int) $before['alerted_at'] >= $every)) {
                $lines[] = strtoupper($check['status']).": {$name}: {$check['summary']}";
                $state[$name]['alerted_at'] = $now;
            } elseif (! $bad && self::rank($before['status']) > 0 && (int) $before['alerted_at'] > 0) {
                $lines[] = "RECOVERED: {$name}: {$check['summary']}";
                $state[$name]['alerted_at'] = 0;
            }
        }

        Cache::forever(self::STATE_KEY, $state);

        if ($lines === []) {
            return null;
        }

        if ($address === '') {
            return 'No alert address configured (EHAIL_HEALTH_ALERT_EMAIL): nothing emailed.';
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            Log::warning('Dot.Ehail health alert (mail only logs on this host, not delivered).', ['lines' => $lines]);

            return 'Mail is not delivering on this host (MAIL_MAILER='.config('mail.default').'): the alert was written to the log only.';
        }

        try {
            Mail::to($address)->send(new HealthAlertMail($report['status'], $lines));
        } catch (Throwable $e) {
            Log::error('Dot.Ehail health alert could not be sent.', ['error' => $e->getMessage()]);

            return 'The alert email could not be sent ('.class_basename($e).').';
        }

        return 'Alert emailed to the ops address ('.count($lines).' line(s)).';
    }

    private static function rank(string $status): int
    {
        return match ($status) {
            HealthReport::FAILING => 2,
            HealthReport::DEGRADED => 1,
            default => 0,
        };
    }
}
