<?php

namespace App\Domain\Fleet;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Fleet\Models\FleetVehicleAssignment;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Operational alerts, computed from records each time the page opens (no alert table, nothing estimated):
 *   document   a driver or vehicle document expired, missing, rejected or expiring within the window
 *   service    a vehicle's next service is overdue or due soon (date or kilometres)
 *   out_of_service  a vehicle the fleet took off the road
 *   idle       a driver offline for longer than the threshold while holding a fleet vehicle
 *   cancellations  a driver with unusually many cancellations in the last days
 * Severity: stop (blocks driving now), caution (act soon), info.
 */
final class FleetAlerts
{
    public function __construct(
        private readonly FleetAccess $access,
        private readonly DocumentOverview $documents,
        private readonly FleetVehicles $vehicles,
        private readonly FleetPerformance $performance,
    ) {}

    /**
     * @return list<array{kind: string, severity: string, title: string, detail: string, subject: string, link: ?array{area: string, id: ?int}}>
     */
    public function forFleet(User $viewer, Fleet $fleet): array
    {
        $alerts = [];

        if ($this->access->can($viewer, $fleet, 'documents')) {
            $rows = collect($this->documents->rows($viewer, $fleet));

            // One alert per driver or vehicle for what blocks it, naming every document involved.
            foreach ($rows->where('satisfied', false)->groupBy(fn ($r) => $r['subject'].':'.$r['subject_id']) as $group) {
                $first = $group->first();
                $alerts[] = [
                    'kind' => 'document', 'severity' => 'stop',
                    'title' => $group->count() === 1
                        ? $first['label'].': '.self::stateLabel($first['state'])
                        : $group->count().' documents to sort out',
                    'detail' => ($group->count() === 1 ? '' : $group->take(3)->map(fn ($r) => $r['label'].' ('.self::stateLabel($r['state']).')')->implode('; ').($group->count() > 3 ? ' and '.($group->count() - 3).' more' : '').'. ')
                        .($first['subject'] === 'driver' ? 'This driver cannot go online until they are sorted.' : 'Nobody can go online in this vehicle until they are sorted.'),
                    'subject' => $first['subject_label'],
                    'link' => ['area' => $first['subject'] === 'driver' ? 'drivers' : 'vehicles', 'id' => $first['subject_id']],
                ];
            }

            foreach ($rows->where('expiring', true) as $row) {
                $alerts[] = [
                    'kind' => 'document', 'severity' => 'caution',
                    'title' => $row['label'].' expires '.Carbon::parse($row['expires_on'])->format('j M Y'),
                    'detail' => ($row['days_left'] === 0 ? 'Today.' : $row['days_left'].' days left.').($row['renewal_pending'] ? ' A renewal is waiting for review.' : ' The renewal needs uploading before then.'),
                    'subject' => $row['subject_label'],
                    'link' => ['area' => $row['subject'] === 'driver' ? 'drivers' : 'vehicles', 'id' => $row['subject_id']],
                ];
            }
        }

        if ($this->access->can($viewer, $fleet, 'maintenance') || $this->access->can($viewer, $fleet, 'vehicles')) {
            $days = (int) config('ehail_fleet.alerts.service_due_days', 14);
            $km = (int) config('ehail_fleet.alerts.service_due_km', 1000);

            foreach ($this->access->vehicleQuery($viewer, $fleet)->whereNull('retired_at')->orderBy('plate_number')->get() as $vehicle) {
                if ($vehicle->out_of_service_at !== null) {
                    $alerts[] = [
                        'kind' => 'out_of_service', 'severity' => 'info',
                        'title' => 'Out of service since '.$vehicle->out_of_service_at->format('j M'),
                        'detail' => $vehicle->out_of_service_reason ?: 'No reason recorded.',
                        'subject' => $vehicle->plate_number,
                        'link' => ['area' => 'vehicles', 'id' => $vehicle->id],
                    ];
                }

                $next = $this->vehicles->nextService($vehicle, $days, $km);

                if ($next['overdue'] || $next['due_soon']) {
                    $parts = array_filter([
                        $next['on'] ? 'due '.$next['on']->format('j M Y') : null,
                        $next['km'] !== null ? 'at '.number_format($next['km'], 0, '.', ' ').' km'.($next['km_left'] !== null ? ' ('.number_format(abs($next['km_left']), 0, '.', ' ').' km '.($next['km_left'] < 0 ? 'over' : 'left').')' : '') : null,
                    ]);
                    $alerts[] = [
                        'kind' => 'service', 'severity' => $next['overdue'] ? 'stop' : 'caution',
                        'title' => $next['overdue'] ? 'Service overdue' : 'Service due soon',
                        'detail' => ucfirst(implode(', ', $parts)).'.',
                        'subject' => $vehicle->plate_number,
                        'link' => ['area' => 'maintenance', 'id' => $vehicle->id],
                    ];
                }
            }
        }

        if ($this->access->can($viewer, $fleet, 'drivers') && $this->access->seesDriverRecords($viewer, $fleet)) {
            $hours = (int) config('ehail_fleet.alerts.idle_assignment_hours', 24);

            FleetVehicleAssignment::query()->open()->where('fleet_id', $fleet->id)->with(['vehicle', 'driverProfile.user:id,name'])->get()
                ->each(function (FleetVehicleAssignment $a) use (&$alerts, $hours) {
                    $row = DriverAvailability::query()->where('user_id', $a->driverProfile?->user_id)->first();
                    $offline = $row === null || $row->state === DriverAvailability::OFFLINE;
                    $since = $row?->state === DriverAvailability::OFFLINE ? ($row->since ?? $a->started_at) : $a->started_at;
                    $since = $since->max($a->started_at);

                    if ($offline && $since->lte(now()->subHours($hours))) {
                        $alerts[] = [
                            'kind' => 'idle', 'severity' => 'info',
                            'title' => 'Offline with a fleet vehicle',
                            'detail' => ($a->driverProfile?->user?->name ?? 'The driver').' has '.$a->vehicle?->plate_number.' and has been offline since '.$since->format('j M H:i').'.',
                            'subject' => (string) ($a->driverProfile?->user?->name ?? 'Driver'),
                            'link' => ['area' => 'drivers', 'id' => $a->driver_profile_id],
                        ];
                    }
                });

            $window = (int) config('ehail_fleet.alerts.cancellation_window_days', 7);
            $min = (int) config('ehail_fleet.alerts.cancellation_min_count', 3);
            $share = (int) config('ehail_fleet.alerts.cancellation_min_share_bp', 2000);
            $drivers = $this->access->drivers($viewer, $fleet)->keyBy('user_id');
            $counts = $this->performance->cancellationWindow($drivers->keys()->map(fn ($id) => (int) $id)->all(), now()->subDays($window), now());

            foreach ($counts as $driverId => $c) {
                $total = $c['cancellations'] + $c['trips'];

                if ($c['cancellations'] >= $min && $total > 0 && intdiv($c['cancellations'] * 10000, $total) >= $share) {
                    $alerts[] = [
                        'kind' => 'cancellations', 'severity' => 'caution',
                        'title' => 'Unusual cancellations',
                        'detail' => $c['cancellations'].' rides cancelled or handed back against '.$c['trips'].' completed in the last '.$window.' days.',
                        'subject' => (string) ($drivers[$driverId]->user?->name ?? 'Driver'),
                        'link' => ['area' => 'drivers', 'id' => $drivers[$driverId]->id],
                    ];
                }
            }
        }

        $order = ['stop' => 0, 'caution' => 1, 'info' => 2];
        usort($alerts, fn ($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return $alerts;
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'approved' => 'approved',
            'pending_review' => 'waiting for review',
            'missing' => 'not uploaded',
            'rejected' => 'rejected',
            'resubmission_requested' => 'upload again',
            'expired' => 'expired',
            'too_old' => 'too old for this vehicle class',
            default => str_replace('_', ' ', $state),
        };
    }
}
