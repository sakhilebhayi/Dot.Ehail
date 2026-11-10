<?php

namespace App\Http\Controllers\Money;

use App\Domain\Earnings\EarningsService;
use App\Domain\Earnings\Incentives\IncentiveEngine;
use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Earnings\Models\IncentiveProgram;
use App\Domain\Earnings\Models\IncentiveProgress;
use App\Domain\Earnings\Models\Payout;
use App\Domain\Payments\CashCollection;
use App\Domain\Payments\Exceptions\PaymentMethodRefused;
use App\Domain\Payments\Models\DriverMoneySetting;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Driver money API: earnings statements, estimate vs actual, cash confirmation, cash opt-in, payouts,
 * incentives. A driver only ever sees their own rows.
 */
class DriverMoneyController extends Controller
{
    public function earnings(Request $request, EarningsService $earnings): JsonResponse
    {
        $data = $this->period($request);

        return response()->json($earnings->forDriver($request->user(), $data['period'], $data['date'] ?? null));
    }

    public function earningsCsv(Request $request, EarningsService $earnings): StreamedResponse
    {
        $data = $this->period($request);
        $rows = $earnings->csvRows(DriverEarning::query()->where('driver_id', $request->user()->id), $data['period'], $data['date'] ?? null);

        return self::csv($rows, 'earnings-'.$data['period'].'-'.($data['date'] ?? now()->toDateString()).'.csv');
    }

    public function earning(Request $request, DriverEarning $earning, EarningsService $earnings): JsonResponse
    {
        Gate::authorize('money.view-earning', $earning);

        return response()->json($earnings->explain($earning));
    }

    public function cashReceived(Request $request, Ride $ride, CashCollection $cash): JsonResponse
    {
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:0', 'max:100000000']]);

        try {
            $payment = $cash->confirm($ride, $request->user(), (int) $data['amount_minor']);
        } catch (PaymentMethodRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['payment_id' => $payment->id, 'status' => $payment->status, 'expected_minor' => $payment->amountMinor(), 'received_minor' => (int) $data['amount_minor']]);
    }

    public function settings(Request $request): JsonResponse
    {
        $setting = DriverMoneySetting::firstOrNew(['user_id' => $request->user()->id]);

        if ($request->isMethod('put')) {
            $data = $request->validate(['accepts_cash' => ['required', 'boolean']]);
            $setting->forceFill(['accepts_cash' => (bool) $data['accepts_cash']])->save();
        }

        return response()->json([
            'accepts_cash' => (bool) $setting->accepts_cash,
            'cash_enabled_on_platform' => (bool) config('money.cash.enabled'),
        ]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $payouts = Payout::with(['batch', 'account'])->where('payee_user_id', $request->user()->id)->orderByDesc('id')->paginate(25);

        return response()->json($payouts->through(fn (Payout $p) => $p->toPublicArray()));
    }

    public function payout(Request $request, Payout $payout): JsonResponse
    {
        Gate::authorize('money.view-payout', $payout);

        $earnings = DriverEarning::whereIn('id', (array) ($payout->composition['earning_ids'] ?? []))->orderBy('earned_at')->get();

        return response()->json($payout->load(['batch', 'account'])->toPublicArray() + [
            'earnings' => $earnings->map->toStatementArray()->values(),
        ]);
    }

    public function incentives(Request $request, IncentiveEngine $engine): JsonResponse
    {
        $driver = $request->user();
        $now = CarbonImmutable::now();

        $programs = IncentiveProgram::whereIn('status', ['active', 'paused'])->where('starts_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->get()
            ->filter(fn (IncentiveProgram $p) => $p->fleet_id === null || \App\Models\DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->where('fleet_id', $p->fleet_id)->exists())
            ->map(function (IncentiveProgram $program) use ($engine, $driver, $now) {
                [$from, $to] = $engine->windowFor($program, $now);
                $stored = IncentiveProgress::where('incentive_program_id', $program->id)->where('driver_id', $driver->id)->where('window_start', $from)->first();
                $measured = $stored?->status === 'earned' ? null : $engine->measure($program, $driver, $from, $now->min($to));

                return $program->toPublicArray() + [
                    'window_start' => $from->toIso8601String(),
                    'window_end' => $to->toIso8601String(),
                    'progress' => $stored?->status === 'earned' ? $stored->progress : $measured['facts'],
                    'percent' => $stored?->status === 'earned' ? 100 : $measured['percent'],
                    // "earned" only once the engine has awarded it; meeting the rules shows as complete pending the award run.
                    'state' => match (true) {
                        $stored?->status === 'earned' => 'earned',
                        $stored?->status === 'budget_exhausted' => 'budget_exhausted',
                        $program->status === 'paused' => 'paused',
                        $measured['unmeasurable'] => 'unmeasurable',
                        $measured['met'] => 'rules_met_awaiting_award',
                        default => 'in_progress',
                    },
                ];
            })->values();

        return response()->json(['programs' => $programs]);
    }

    /**
     * @return array{period: string, date?: string}
     */
    private function period(Request $request): array
    {
        return $request->validate([
            'period' => ['sometimes', Rule::in(['day', 'week', 'month'])],
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]) + ['period' => 'week'];
    }

    /**
     * @param  array<int, array<int, string|int|null>>  $rows
     */
    public static function csv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            foreach ($rows as $row) {
                // Neutralise spreadsheet formulas in any text cell.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) && ! is_numeric($v) ? "'".$v : $v, $row), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
