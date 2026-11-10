<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Ops\Audit\OpsAudit;
use App\Domain\Ops\Insights\InsightsQuery;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Insights: real operational measures over a date range, each with its definition, plus a CSV of every figure. */
class InsightsController extends Controller
{
    public function index(Request $request, InsightsQuery $insights): View
    {
        [$from, $to, $grain] = $this->range($request);

        return view('ops.insights.index', ['data' => $insights->all($from, $to, $grain), 'from' => $from, 'to' => $to, 'grain' => $grain, 'definitions' => InsightsQuery::DEFINITIONS]);
    }

    public function export(Request $request, InsightsQuery $insights, OpsAudit $audit): StreamedResponse
    {
        [$from, $to, $grain] = $this->range($request);
        $data = $insights->all($from, $to, $grain);
        $audit->action('ops.insights_exported', $request->user(), $request->user(), ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'grain' => $grain]);

        $rows = [];
        foreach (['ride_volume', 'completed_trips'] as $metric) {
            foreach ($data[$metric]['rows'] as $r) {
                $rows[] = [$metric, $r['key'], 'count', $r['value']];
            }
        }
        foreach ($data['cancellations']['rows'] as $r) {
            $rows[] = ['cancellations', $from->toDateString().'..'.$to->toDateString(), $r['state'].'_count', $r['count']];
            $rows[] = ['cancellations', $from->toDateString().'..'.$to->toDateString(), $r['state'].'_rate_percent', $r['rate_bp'] === null ? '' : number_format($r['rate_bp'] / 100, 2, '.', '')];
        }
        foreach ($data['trip_duration']['rows'] as $r) {
            $rows[] = ['trip_duration', $r['key'], 'average_seconds', $r['average_s'] ?? ''];
            $rows[] = ['trip_duration', $r['key'], 'trips', $r['count']];
        }
        $p = $data['pickup_eta'];
        $range = $from->toDateString().'..'.$to->toDateString();
        array_push($rows, ['pickup_eta', $range, 'rides', $p['count']], ['pickup_eta', $range, 'estimated_average_seconds', $p['estimated_s'] ?? ''], ['pickup_eta', $range, 'actual_average_seconds', $p['actual_s'] ?? ''], ['pickup_eta', $range, 'later_than_estimate', $p['late']]);
        $u = $data['utilisation'];
        array_push($rows, ['utilisation', $range, 'online_seconds', $u['online_s']], ['utilisation', $range, 'on_trip_seconds', $u['on_trip_s']], ['utilisation', $range, 'rate_percent', $u['rate_bp'] === null ? '' : number_format($u['rate_bp'] / 100, 2, '.', '')]);
        foreach ($data['retention']['rows'] as $r) {
            $rows[] = ['retention', $r['cohort'], 'cohort_size', $r['size']];
            foreach ($r['cells'] as $k => $cell) {
                if ($cell !== null) {
                    $rows[] = ['retention', $r['cohort'], 'month_'.($k + 1).'_returned', $cell['count']];
                }
            }
        }
        foreach ($data['revenue']['rows'] as $r) {
            foreach (['commission', 'fees', 'driver_earnings', 'refunds', 'promotions'] as $m) {
                $rows[] = ['revenue', $r['key'], $m.'_minor', $r[$m]];
            }
        }
        foreach ($data['coverage']['rows'] as $r) {
            $rows[] = ['coverage', $r['area'], 'requests', $r['requests']];
            $rows[] = ['coverage', $r['area'], 'expired', $r['expired']];
        }
        $rows[] = ['coverage', 'outside every active area', 'requests', $data['coverage']['outside']['requests']];
        foreach ($data['incidents']['rows'] as $r) {
            foreach (['critical', 'high', 'medium', 'low'] as $s) {
                $rows[] = ['incidents', $r['key'], $s, $r[$s]];
            }
        }

        $name = sprintf('ehail-insights-%s-to-%s.csv', $from->toDateString(), $to->toDateString());

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['metric', 'period', 'measure', 'value'], escape: '');
            foreach ($rows as $row) {
                // Formula-neutralised: any cell starting with = + - @ is prefixed with an apostrophe.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row), escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    private function range(Request $request): array
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'grain' => ['nullable', Rule::in(['day', 'week', 'month'])],
        ]);
        $to = CarbonImmutable::parse($v['to'] ?? now()->toDateString())->endOfDay();
        $from = CarbonImmutable::parse($v['from'] ?? $to->subDays(29)->toDateString())->startOfDay();
        if ($from->diffInDays($to) > 400) {
            $from = $to->subDays(400)->startOfDay();
        }

        return [$from, $to, $v['grain'] ?? ($from->diffInDays($to) > 62 ? 'week' : 'day')];
    }
}
