<?php

namespace App\Domain\Security\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Retention for personal information no other purge covers (POPIA s14, docs/R46_PRIVACY.md section 5). Daily from
 * the scheduler; every period is in config/ehail_security.php `retention`. Location data (trip points, driver
 * locations, route cache), unused quotes, delivery records, business rider phones and idempotency keys already
 * have their own owners' purges and are not repeated here. Financial records (ledger, receipts, payments,
 * payouts, earnings) are statutory and never purged here. Each step is idempotent and bounded.
 */
class PrivacyPurge extends Command
{
    protected $signature = 'ehail:privacy-purge {--dry-run : Count what would be removed without removing it}';

    protected $description = 'Apply the personal-information retention schedule (notifications, quote coordinates, planned routes, old incidents, closed support cases, superseded identity files, declined trusted contacts, old audit and webhook rows)';

    private bool $dry = false;

    /** @var array<string, int> */
    private array $counts = [];

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $days = fn (string $key) => now()->subDays(max(1, (int) config("ehail_security.retention.{$key}")));

        // Read notifications after the period; unread ones after twice the period.
        $this->step('notifications', DB::table('notifications')->where(fn ($q) => $q
            ->where(fn ($q) => $q->whereNotNull('read_at')->where('created_at', '<', $days('notifications_read')))
            ->orWhere('created_at', '<', now()->subDays(2 * max(1, (int) config('ehail_security.retention.notifications_read'))))), 'delete');

        // Consumed quotes keep the fare (ride_fares has the snapshot); their exact coordinates are coarsened to ~1 km.
        if (Schema::hasTable('fare_quotes')) {
            $quotes = DB::table('fare_quotes')->whereNotNull('consumed_at')->where('consumed_at', '<', $days('consumed_quote_coordinates'))->whereNull('coarsened_at');
            $this->count('fare_quote_coordinates', $quotes);
            if (! $this->dry) {
                $quotes->orderBy('id')->chunkById(500, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('fare_quotes')->where('id', $row->id)->update([
                            'pickup_lat' => round((float) $row->pickup_lat, 2), 'pickup_lng' => round((float) $row->pickup_lng, 2),
                            'dropoff_lat' => round((float) $row->dropoff_lat, 2), 'dropoff_lng' => round((float) $row->dropoff_lng, 2),
                            'coarsened_at' => now(),
                        ]);
                    }
                });
            }
        }

        // The planned road route of finished rides (the distance summary stays).
        if (Schema::hasTable('ride_routes')) {
            $this->step('planned_polylines', DB::table('ride_routes')->whereNotNull('planned_polyline')
                ->whereIn('ride_id', DB::table('rides')->whereNotNull('completed_at')->where('completed_at', '<', $days('ride_route_polylines'))->select('id')), ['planned_polyline' => null]);
        }

        // Incidents closed longer ago than the incident retention: the narrative stays for statistics, the position
        // and the context snapshot (names, addresses, trail excerpt) go.
        $this->step('incident_snapshots', DB::table('safety_incidents')->whereIn('status', ['resolved', 'closed'])
            ->where(fn ($q) => $q->whereNotNull('context')->orWhereNotNull('location_lat'))
            ->where(fn ($q) => $q->where('closed_at', '<', $days('incidents'))->orWhere(fn ($q) => $q->whereNull('closed_at')->where('resolved_at', '<', $days('incidents')))),
            ['context' => null, 'location_lat' => null, 'location_lng' => null, 'location_accuracy_m' => null, 'description' => '[Removed after the retention period]']);

        $this->supportCases($days('support_closed'));
        $this->supersededIdentityFiles($days('verification_files_superseded'));

        // Trusted contacts who declined, or never confirmed: only the opt-out hash would be needed, the row goes.
        $this->step('declined_trusted_contacts', DB::table('trusted_contacts')->whereNull('confirmed_at')
            ->where(fn ($q) => $q->where('opted_out_at', '<', $days('trusted_contacts_declined'))
                ->orWhere(fn ($q) => $q->whereNull('opted_out_at')->where('confirmation_sent_at', '<', $days('trusted_contacts_declined')))), 'delete');

        // An offline driver's last position (kept by older code paths, D-406).
        if (Schema::hasTable('driver_availability')) {
            $this->step('offline_positions', DB::table('driver_availability')->where('state', 'offline')->whereNotNull('last_lat'), ['last_lat' => null, 'last_lng' => null]);
        }

        // Decided business approval requests: who, where and when, after the period.
        if (Schema::hasTable('business_approvals')) {
            $this->step('business_approval_details', DB::table('business_approvals')->whereNotNull('decided_at')->where('decided_at', '<', $days('business_approvals'))
                ->where(fn ($q) => $q->whereNotNull('guest_name')->orWhereNotNull('pickup_address')),
                ['guest_name' => null, 'pickup_address' => null, 'dropoff_address' => null, 'purpose' => null]);
        }

        $this->step('failed_jobs', DB::table('failed_jobs')->where('failed_at', '<', $days('failed_jobs')), 'delete');
        $this->step('password_reset_tokens', DB::table('password_reset_tokens')->where('created_at', '<', now()->subDay()), 'delete');
        $this->step('webhook_events', DB::table('payment_webhook_events')->whereNotNull('processed_at')->where('created_at', '<', $days('webhook_events')), 'delete');
        $this->step('audit_logs', DB::table('audit_logs')->where('created_at', '<', $days('audit_logs')), 'delete');

        foreach ($this->counts as $what => $n) {
            $this->line(($this->dry ? '[dry run] ' : '').str_pad($what, 28).$n);
        }

        return self::SUCCESS;
    }

    /**
     * @param  'delete'|array<string, mixed>  $action
     */
    private function step(string $name, $query, string|array $action): void
    {
        $this->count($name, $query);

        if ($this->dry || $this->counts[$name] === 0) {
            return;
        }

        $action === 'delete' ? $query->delete() : $query->update($action + (Schema::hasColumn($query->from, 'updated_at') ? ['updated_at' => now()] : []));
    }

    private function count(string $name, $query): void
    {
        $this->counts[$name] = (clone $query)->count();
    }

    private function supportCases($cutoff): void
    {
        $cases = DB::table('support_cases')->where('status', 'closed')->where(fn ($q) => $q->where('closed_at', '<', $cutoff)->orWhere(fn ($q) => $q->whereNull('closed_at')->where('updated_at', '<', $cutoff)))->select('id');
        $messages = DB::table('support_messages')->whereIn('support_case_id', $cases)->where('body', '!=', '[Removed after the retention period]');
        $this->count('support_messages', $messages);

        if ($this->dry) {
            return;
        }

        (clone $messages)->whereNotNull('attachment_path')->orderBy('id')->each(function ($m) {
            Storage::disk((string) ($m->attachment_disk ?: 'safety-files'))->delete((string) $m->attachment_path);
        });

        $messages->update(['body' => '[Removed after the retention period]', 'attachment_path' => null, 'attachment_name' => null, 'attachment_disk' => null]);
    }

    private function supersededIdentityFiles($cutoff): void
    {
        $documents = DB::table('verification_documents')->whereIn('status', ['superseded', 'rejected', 'expired'])
            ->where('updated_at', '<', $cutoff)->where('file_path', 'not like', 'purged/%');
        $this->count('identity_files', $documents);

        if ($this->dry) {
            return;
        }

        (clone $documents)->orderBy('id')->each(function ($d) {
            Storage::disk((string) $d->disk)->delete((string) $d->file_path);
            DB::table('verification_documents')->where('id', $d->id)->update(['file_path' => 'purged/'.$d->id, 'updated_at' => now()]);
        });
    }
}
