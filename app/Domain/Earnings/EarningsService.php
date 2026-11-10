<?php

namespace App\Domain\Earnings;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Domain\Payments\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Driver earnings statements (contract: EarningsService::forDriver(period)). Every figure is summed
 * from driver_earnings rows; averages are computed only over periods that have trips and are null
 * when there is nothing to average. Nothing is estimated or extrapolated.
 */
class EarningsService
{
    public function __construct(private Ledger $ledger) {}

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function periodBounds(string $period, ?string $date = null): array
    {
        $tz = \App\Support\Ui\LocalTime::timezone();
        $anchor = $date === null ? CarbonImmutable::now($tz) : CarbonImmutable::parse($date, $tz);

        return match ($period) {
            'day' => [$anchor->startOfDay(), $anchor->endOfDay()],
            'month' => [$anchor->startOfMonth(), $anchor->endOfMonth()],
            default => [$anchor->startOfWeek(CarbonImmutable::MONDAY), $anchor->endOfWeek(CarbonImmutable::SUNDAY)],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function forDriver(User $driver, string $period = 'week', ?string $date = null): array
    {
        [$from, $to] = self::periodBounds($period, $date);

        return $this->statement(DriverEarning::query()->where('driver_id', $driver->id), $from, $to, $period) + [
            'balances' => $this->balancesFor($driver),
        ];
    }

    /**
     * Statement for every driver in a fleet (fleet owner view).
     *
     * @param  array<int, int>  $driverIds
     * @return array<string, mixed>
     */
    public function forDrivers(array $driverIds, string $period = 'week', ?string $date = null): array
    {
        [$from, $to] = self::periodBounds($period, $date);
        $statement = $this->statement(DriverEarning::query()->whereIn('driver_id', $driverIds), $from, $to, $period);

        $statement['by_driver'] = DriverEarning::query()->whereIn('driver_id', $driverIds)
            ->whereBetween('earned_at', [$from, $to])
            ->get()
            ->groupBy('driver_id')
            ->map(fn (Collection $rows, $driverId) => [
                'driver_id' => (int) $driverId,
                'trips' => $rows->where('kind', DriverEarning::KIND_TRIP)->count(),
                'fare_minor' => (int) $rows->sum('fare_minor'),
                'commission_minor' => (int) $rows->sum('commission_minor'),
                'net_minor' => (int) $rows->sum('net_minor'),
            ])->values()->all();

        return $statement;
    }

    /**
     * What the platform owes the driver and what cash they hold, from the ledger.
     *
     * @return array<string, int>
     */
    public function balancesFor(User $driver): array
    {
        $currency = Money::currency();
        $available = (int) DriverEarning::where('driver_id', $driver->id)->where('status', DriverEarning::AVAILABLE)->sum('net_minor');
        $pending = (int) DriverEarning::where('driver_id', $driver->id)->where('status', DriverEarning::PENDING)->sum('net_minor');
        $cashHeld = max(0, $this->ledger->balance(Account::cashWithDriver($driver->id), $currency));

        // Cash fares the driver holds belong to the platform; they are netted against what the
        // platform owes. What is left either way is the next payout or the commission still owed.
        return [
            'available_minor' => $available,
            'pending_minor' => $pending,
            'cash_held_minor' => $cashHeld,
            'cash_commission_owed_minor' => max(0, $cashHeld - $available),
            'next_payout_estimate_minor' => max(0, $available - $cashHeld),
        ];
    }

    /**
     * One row per earning in the period, for CSV export.
     *
     * @return array<int, array<int, string|int|null>>
     */
    public function csvRows(Builder $scope, string $period, ?string $date): array
    {
        [$from, $to] = self::periodBounds($period, $date);
        $rows = [['earned_at', 'kind', 'ride_id', 'currency', 'fare', 'commission', 'deductions', 'incentive', 'net', 'cash_collected', 'estimate', 'status', 'payout_id']];

        $scope->whereBetween('earned_at', [$from, $to])->orderBy('earned_at')->orderBy('id')->each(function (DriverEarning $e) use (&$rows) {
            $rows[] = [
                $e->earned_at->toIso8601String(), $e->kind, $e->ride_id, $e->currency,
                Money::toDecimalString($e->fare_minor), Money::toDecimalString($e->commission_minor),
                Money::toDecimalString($e->deductions_minor), Money::toDecimalString($e->incentive_minor),
                Money::toDecimalString($e->net_minor), Money::toDecimalString($e->cash_collected_minor),
                $e->estimate_minor === null ? null : Money::toDecimalString($e->estimate_minor),
                $e->status, $e->payout_id,
            ];
        });

        return $rows;
    }

    /**
     * Estimate vs actual for one trip, with the reasons pricing gave.
     *
     * @return array<string, mixed>
     */
    public function explain(DriverEarning $earning): array
    {
        $b = (array) $earning->breakdown;

        return $earning->toStatementArray() + [
            'estimate_vs_actual' => [
                'estimate_minor' => $earning->estimate_minor,
                'actual_fare_minor' => $earning->fare_minor,
                'difference_minor' => $earning->estimate_minor === null ? null : $earning->fare_minor - $earning->estimate_minor,
                'reasons' => $b['reasons'] ?? [],
                'note' => $earning->estimate_minor === null ? 'No estimate was recorded for this trip.' : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statement(Builder $scope, CarbonImmutable $from, CarbonImmutable $to, string $period): array
    {
        $rows = (clone $scope)->whereBetween('earned_at', [$from, $to])->orderByDesc('earned_at')->orderByDesc('id')->get();
        $trips = $rows->where('kind', DriverEarning::KIND_TRIP);
        $tripCount = $trips->count();

        $byDay = $trips->groupBy(fn (DriverEarning $e) => $e->earned_at->copy()->timezone(\App\Support\Ui\LocalTime::timezone())->toDateString());
        $activeDays = $byDay->count();

        return [
            'period' => $period,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'currency' => Money::currency(),
            'totals' => [
                'trips' => $tripCount,
                'fare_minor' => (int) $rows->sum('fare_minor'),
                'commission_minor' => (int) $rows->sum('commission_minor'),
                'deductions_minor' => (int) $rows->sum('deductions_minor'),
                'incentives_minor' => (int) $rows->where('kind', DriverEarning::KIND_INCENTIVE)->sum('net_minor'),
                'cancellation_fees_minor' => (int) $rows->where('kind', DriverEarning::KIND_CANCELLATION_FEE)->sum('net_minor'),
                'adjustments_minor' => (int) $rows->where('kind', DriverEarning::KIND_ADJUSTMENT)->sum('net_minor'),
                'net_minor' => (int) $rows->sum('net_minor'),
                'cash_collected_minor' => (int) $rows->sum('cash_collected_minor'),
                'pending_minor' => (int) $rows->where('status', DriverEarning::PENDING)->sum('net_minor'),
                'available_minor' => (int) $rows->where('status', DriverEarning::AVAILABLE)->sum('net_minor'),
                'paid_minor' => (int) $rows->where('status', DriverEarning::PAID)->sum('net_minor'),
            ],
            'averages' => [
                'net_per_trip_minor' => $tripCount > 0 ? intdiv((int) $trips->sum('net_minor'), $tripCount) : null,
                'net_per_active_day_minor' => $activeDays > 0 ? intdiv((int) $trips->sum('net_minor'), $activeDays) : null,
                'active_days' => $activeDays,
                'basis' => 'Completed trips in this period only.',
            ],
            'earnings' => $rows->map(fn (DriverEarning $e) => $e->toStatementArray())->values()->all(),
        ];
    }
}
