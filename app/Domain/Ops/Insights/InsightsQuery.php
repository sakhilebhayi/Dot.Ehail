<?php

namespace App\Domain\Ops\Insights;

use App\Domain\Geo\LatLng;
use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Payments\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Operational insights from real rows only (brief section 18). Every metric carries its exact definition, which the
 * page prints next to it. Day buckets are SQL (UTC dates, Sql::day), weeks and months roll up in PHP. Results are
 * plain arrays cached for 60 seconds per range and grain. Nothing here is estimated or filled in: a period with no
 * rows is 0, an average with no rows is null ("no data"), a measurement that does not exist yet says so.
 */
class InsightsQuery
{
    public const CACHE_SECONDS = 60;

    /**
     * @var array<string, string> exact definitions shown on the page and in the CSV, in plain words: no table or
     *                            column names (D-1914). The queries below are the technical record.
     */
    public const DEFINITIONS = [
        'ride_volume' => 'Every ride requested, counted on the day it was requested (scheduled or not, whatever happened next).',
        'completed_trips' => 'Rides that finished, counted on the day they finished.',
        'cancellations' => 'Of the rides requested in the period, the share that ended each way.',
        'pickup_eta' => 'For rides whose driver reached the pickup in the period: estimated is the pickup time the driver was shown when accepting (with how it was estimated); actual is the time from accepting to arriving.',
        'trip_duration' => 'Average time from the trip starting to the trip ending, over rides that finished in the period.',
        'utilisation' => 'Time drivers spent with a ride (from being assigned to the trip ending) as a share of all their time online, measured inside the period. Paused and offline time is left out.',
        'retention' => 'Passengers grouped by the month of their first finished ride; each later month shows the share of that group who finished at least one ride in it.',
        'revenue' => 'From the money ledger: platform revenue is commission and fees kept, less any reversed; driver earnings are what drivers and fleets were credited for trips, less any reversed, before payouts.',
        'coverage' => 'Rides requested in the period whose pickup lies inside each active service area; the expired rate is the share of those that ended with no driver found.',
        'incidents' => 'Safety incidents counted on the day they were opened, by severity.',
    ];

    public function __construct(private readonly LedgerPeriods $ledger) {}

    /** @return array<string, mixed> */
    public function all(CarbonImmutable $from, CarbonImmutable $to, string $grain): array
    {
        $key = sprintf('ops-insights:%s:%s:%s', $from->format('YmdHis'), $to->format('YmdHis'), $grain);

        return Cache::remember($key, self::CACHE_SECONDS, fn () => [
            'generated_at' => now()->toIso8601String(),
            'ride_volume' => $this->countByDay('rides', 'created_at', $from, $to, $grain),
            'completed_trips' => $this->countByDay('rides', 'completed_at', $from, $to, $grain, fn ($q) => $q->where('status', 'completed')),
            'cancellations' => $this->cancellations($from, $to),
            'pickup_eta' => $this->pickupEta($from, $to),
            'trip_duration' => $this->tripDuration($from, $to, $grain),
            'utilisation' => $this->utilisation($from, $to),
            'retention' => $this->retention($to),
            'revenue' => $this->ledger->revenue($from, $to, $grain, Money::currency()),
            'coverage' => $this->coverage($from, $to),
            'incidents' => $this->incidents($from, $to, $grain),
        ]);
    }

    /** @return array{rows: list<array{key: string, label: string, value: int}>, total: int} */
    public function countByDay(string $table, string $column, CarbonImmutable $from, CarbonImmutable $to, string $grain, ?callable $scope = null): array
    {
        $day = Sql::day($column);
        $query = DB::table($table)->whereBetween($column, [$from, $to])->selectRaw("{$day} as d, COUNT(*) as n")->groupBy(DB::raw($day));
        if ($scope) {
            $scope($query);
        }
        $byDay = $query->pluck('n', 'd')->map(fn ($n) => (int) $n);

        $rows = [];
        foreach (Periods::buckets($from, $to, $grain) as $bucket) {
            $rows[] = ['key' => $bucket['key'], 'label' => $bucket['label'], 'value' => array_sum(array_map(fn ($d) => $byDay[$d] ?? 0, $bucket['days']))];
        }

        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'value'))];
    }

    /** @return array<string, mixed> */
    public function cancellations(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = DB::table('rides')->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $total = array_sum($counts->all());
        $states = [
            'cancelled_by_passenger' => 'Cancelled by the passenger',
            'cancelled_by_driver' => 'Cancelled by the driver',
            'cancelled_by_operator' => 'Cancelled by operations',
            'expired' => 'No driver found',
            'no_show' => 'Passenger did not show',
            'failed' => 'Failed',
        ];

        $rows = [];
        foreach ($states as $state => $label) {
            $n = $counts[$state] ?? 0;
            $rows[] = ['state' => $state, 'label' => $label, 'count' => $n, 'rate_bp' => $total > 0 ? intdiv($n * 10000 + intdiv($total, 2), $total) : null];
        }

        return ['total' => $total, 'completed' => $counts['completed'] ?? 0, 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    public function pickupEta(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $actual = Sql::seconds('r.accepted_at', 'r.arrived_at');
        $rows = DB::table('rides as r')
            ->join('ride_offers as o', fn ($j) => $j->on('o.ride_id', '=', 'r.id')->on('o.driver_id', '=', 'r.driver_id')->where('o.status', '=', 'accepted'))
            ->whereNotNull('r.accepted_at')->whereNotNull('r.arrived_at')->whereNotNull('o.pickup_eta_s')
            ->whereBetween('r.arrived_at', [$from, $to])
            ->selectRaw("o.eta_method as method, COUNT(*) as n, AVG(o.pickup_eta_s) as est, AVG({$actual}) as act, SUM(CASE WHEN {$actual} > o.pickup_eta_s THEN 1 ELSE 0 END) as late")
            ->groupBy('o.eta_method')
            ->get();

        $n = (int) $rows->sum('n');
        $weighted = fn (string $field) => $n > 0 ? (int) round($rows->sum(fn ($r) => (float) $r->{$field} * (int) $r->n) / $n) : null;

        return [
            'count' => $n,
            'estimated_s' => $weighted('est'),
            'actual_s' => $weighted('act'),
            'late' => (int) $rows->sum('late'),
            'by_method' => $rows->map(fn ($r) => ['method' => (string) $r->method, 'count' => (int) $r->n, 'estimated_s' => (int) round((float) $r->est), 'actual_s' => (int) round((float) $r->act), 'late' => (int) $r->late])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function tripDuration(CarbonImmutable $from, CarbonImmutable $to, string $grain): array
    {
        $seconds = Sql::seconds('started_at', 'completed_at');
        $day = Sql::day('completed_at');
        $byDay = DB::table('rides')->where('status', 'completed')->whereNotNull('started_at')->whereBetween('completed_at', [$from, $to])
            ->selectRaw("{$day} as d, COUNT(*) as n, SUM({$seconds}) as s")->groupBy(DB::raw($day))->get()->keyBy('d');

        $rows = [];
        $n = 0;
        $s = 0.0;
        foreach (Periods::buckets($from, $to, $grain) as $bucket) {
            $bn = 0;
            $bs = 0.0;
            foreach ($bucket['days'] as $d) {
                if ($r = $byDay->get($d)) {
                    $bn += (int) $r->n;
                    $bs += (float) $r->s;
                }
            }
            $rows[] = ['key' => $bucket['key'], 'label' => $bucket['label'], 'count' => $bn, 'average_s' => $bn > 0 ? (int) round($bs / $bn) : null];
            $n += $bn;
            $s += $bs;
        }

        return ['count' => $n, 'average_s' => $n > 0 ? (int) round($s / $n) : null, 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    public function utilisation(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $firstMeasured = DB::table('ops_availability_spans')->min('started_at');
        $end = $to->greaterThan(now()) ? CarbonImmutable::now() : $to;

        $spans = DB::table('ops_availability_spans')
            ->whereIn('state', ['online', 'on_trip'])
            ->where('started_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->get(['driver_id', 'state', 'started_at', 'ended_at']);

        $online = 0;
        $onTrip = 0;
        $drivers = [];
        foreach ($spans as $span) {
            $start = CarbonImmutable::parse($span->started_at)->max($from);
            $stop = ($span->ended_at ? CarbonImmutable::parse($span->ended_at) : CarbonImmutable::now())->min($end);
            $seconds = max(0, $stop->getTimestamp() - $start->getTimestamp());
            $online += $seconds;
            if ($span->state === 'on_trip') {
                $onTrip += $seconds;
            }
            $drivers[$span->driver_id] = true;
        }

        return [
            'measured_since' => $firstMeasured ? CarbonImmutable::parse($firstMeasured)->toIso8601String() : null,
            'online_s' => $online,
            'on_trip_s' => $onTrip,
            'drivers' => count($drivers),
            'rate_bp' => $online > 0 ? intdiv($onTrip * 10000 + intdiv($online, 2), $online) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function retention(CarbonImmutable $to, int $cohorts = 6): array
    {
        $month = Sql::month('completed_at');
        $pairs = DB::table('rides')->where('status', 'completed')->whereNotNull('completed_at')->where('completed_at', '<=', $to)
            ->selectRaw("passenger_id, {$month} as m")->groupBy('passenger_id', DB::raw($month))->get();

        $first = [];
        $active = [];
        foreach ($pairs as $p) {
            $first[$p->passenger_id] = isset($first[$p->passenger_id]) ? min($first[$p->passenger_id], $p->m) : $p->m;
            $active[$p->m][$p->passenger_id] = true;
        }

        $lastMonth = $to->format('Y-m');
        $months = [];
        for ($i = $cohorts - 1; $i >= 0; $i--) {
            $months[] = $to->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m');
        }

        $rows = [];
        foreach ($months as $cohortMonth) {
            $members = array_keys(array_filter($first, fn ($m) => $m === $cohortMonth));
            $size = count($members);
            $cells = [];
            $later = CarbonImmutable::createFromFormat('!Y-m', $cohortMonth);
            for ($k = 1; $k < $cohorts; $k++) {
                $target = $later->addMonthsNoOverflow($k)->format('Y-m');
                if ($target > $lastMonth) {
                    $cells[] = null;

                    continue;
                }
                $returned = count(array_filter($members, fn ($pid) => isset($active[$target][$pid])));
                $cells[] = ['count' => $returned, 'rate_bp' => $size > 0 ? intdiv($returned * 10000 + intdiv($size, 2), $size) : null];
            }
            $rows[] = ['cohort' => $cohortMonth, 'label' => CarbonImmutable::createFromFormat('!Y-m', $cohortMonth)->format('M Y'), 'size' => $size, 'cells' => $cells];
        }

        return ['rows' => $rows, 'passengers' => count($first)];
    }

    /** @return array<string, mixed> */
    public function coverage(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $areas = ServiceArea::query()->where('is_active', true)->get();
        $stats = [];
        foreach ($areas as $area) {
            $stats[$area->id] = ['area' => $area->name, 'requests' => 0, 'expired' => 0];
        }
        $outside = ['requests' => 0, 'expired' => 0];
        $noCoordinates = 0;

        DB::table('rides')->whereBetween('created_at', [$from, $to])->orderBy('id')
            ->select(['id', 'pickup_lat', 'pickup_lng', 'status'])
            ->chunkById(2000, function ($rides) use ($areas, &$stats, &$outside, &$noCoordinates) {
                foreach ($rides as $ride) {
                    if ($ride->pickup_lat === null || $ride->pickup_lng === null) {
                        $noCoordinates++;

                        continue;
                    }
                    $point = LatLng::from((float) $ride->pickup_lat, (float) $ride->pickup_lng);
                    $hit = $areas->first(fn (ServiceArea $a) => $a->containsPoint($point));
                    $expired = $ride->status === 'expired' ? 1 : 0;
                    if ($hit) {
                        $stats[$hit->id]['requests']++;
                        $stats[$hit->id]['expired'] += $expired;
                    } else {
                        $outside['requests']++;
                        $outside['expired'] += $expired;
                    }
                }
            });

        $rows = array_values(array_map(fn ($s) => $s + ['rate_bp' => $s['requests'] > 0 ? intdiv($s['expired'] * 10000 + intdiv($s['requests'], 2), $s['requests']) : null], $stats));

        return ['rows' => $rows, 'outside' => $outside, 'no_coordinates' => $noCoordinates];
    }

    /** @return array<string, mixed> */
    public function incidents(CarbonImmutable $from, CarbonImmutable $to, string $grain): array
    {
        $day = Sql::day('created_at');
        $byDay = DB::table('safety_incidents')->whereBetween('created_at', [$from, $to])
            ->selectRaw("{$day} as d, severity, COUNT(*) as n")->groupBy(DB::raw($day), 'severity')->get();

        $rows = [];
        foreach (Periods::buckets($from, $to, $grain) as $bucket) {
            $row = ['key' => $bucket['key'], 'label' => $bucket['label'], 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'value' => 0];
            foreach ($byDay->whereIn('d', $bucket['days']) as $r) {
                $row[$r->severity] = ($row[$r->severity] ?? 0) + (int) $r->n;
                $row['value'] += (int) $r->n;
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'value'))];
    }
}
