<?php

namespace App\Http\Controllers\Fleet;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Fleet\FleetEarnings;
use App\Domain\Payments\Money;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fleet earnings per period, per driver and per vehicle, the commission plans that apply, and the ledger check.
 */
class FleetEarningsController extends FleetController
{
    public function index(Request $request, FleetEarnings $earnings): View
    {
        $fleet = $this->fleet($request, 'earnings');
        [$period, $date] = $this->period($request);

        return view('fleet.earnings', [
            'fleet' => $fleet,
            'fleets' => $this->access->fleetsFor($request->user()),
            'statement' => $earnings->statement($request->user(), $fleet, $period, $date),
            'reconciliation' => $earnings->reconciliation($request->user(), $fleet),
            'fleetPlans' => $earnings->commissionPlans($fleet),
            'marketPlans' => $earnings->marketPlans(),
            'payeeMode' => $fleet->payee_mode ?? config('money.payouts.payee_for_fleet_drivers'),
            'period' => $period,
            'date' => $date,
        ]);
    }

    public function csv(Request $request, FleetEarnings $earnings): StreamedResponse
    {
        $fleet = $this->fleet($request, 'earnings');
        [$period, $date] = $this->period($request);
        $statement = $earnings->statement($request->user(), $fleet, $period, $date);
        $names = User::query()->whereIn('id', $statement['rows']->pluck('driver_id'))->pluck('name', 'id');

        $rows = [['earned_at_sast', 'driver', 'kind', 'ride_id', 'vehicle_id', 'currency', 'fare', 'commission', 'deductions', 'net', 'cash_collected', 'status', 'payee']];

        foreach ($statement['rows']->sortBy('earned_at') as $e) {
            /** @var DriverEarning $e */
            $rows[] = [
                \App\Support\Ui\LocalTime::at($e->earned_at)->toIso8601String(), $names[$e->driver_id] ?? $e->driver_id, $e->kind, $e->ride_id, $e->ride?->vehicle_id, $e->currency,
                Money::toDecimalString($e->fare_minor), Money::toDecimalString($e->commission_minor), Money::toDecimalString($e->deductions_minor),
                Money::toDecimalString($e->net_minor), Money::toDecimalString($e->cash_collected_minor), $e->status, $e->payee_type,
            ];
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            foreach ($rows as $row) {
                // Neutralise spreadsheet formulas in text cells.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $row), escape: '');
            }

            fclose($out);
        }, 'fleet-'.$fleet->id.'-earnings-'.$period.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function period(Request $request): array
    {
        $period = in_array($request->query('period'), ['day', 'week', 'month'], true) ? $request->query('period') : 'week';
        $date = $request->query('date');
        $date = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false ? Carbon::parse($date)->toDateString() : null;

        return [$period, $date];
    }
}
