<?php

namespace App\Domain\Fleet;

use App\Domain\Earnings\EarningsService;
use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Domain\Payments\Money;
use App\Domain\Pricing\Models\CommissionPlan;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Fleet earnings from the money domain's records: driver_earnings rows written for this fleet (fleet_id is set when
 * the earning is recognised) per period, per driver and per vehicle (the trip's vehicle), and the ledger's own view
 * of what is owed (fleet_payable:{fleet}, driver_payable:{driver}) side by side with the unpaid earnings, so a
 * mismatch is visible instead of hidden. No figure here is estimated.
 */
final class FleetEarnings
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly FleetAccess $access,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function statement(User $viewer, Fleet $fleet, string $period = 'week', ?string $date = null): array
    {
        [$from, $to] = EarningsService::periodBounds($period, $date);
        $rows = $this->scope($viewer, $fleet)->whereBetween('earned_at', [$from, $to])->with('ride:id,vehicle_id')->orderByDesc('earned_at')->get();
        $trips = $rows->where('kind', DriverEarning::KIND_TRIP);
        $names = User::query()->whereIn('id', $rows->pluck('driver_id')->unique())->pluck('name', 'id');
        $plates = Vehicle::query()->whereIn('id', $trips->pluck('ride.vehicle_id')->filter()->unique())->pluck('plate_number', 'id');

        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'currency' => Money::currency(),
            'totals' => $this->totals($rows),
            'by_driver' => $rows->groupBy('driver_id')->map(fn (Collection $group, $driverId) => [
                'driver_id' => (int) $driverId,
                'name' => (string) ($names[$driverId] ?? 'Driver #'.$driverId),
            ] + $this->totals($group))->sortByDesc('net_minor')->values()->all(),
            'by_vehicle' => $trips->groupBy(fn (DriverEarning $e) => (int) ($e->ride?->vehicle_id ?? 0))->map(fn (Collection $group, $vehicleId) => [
                'vehicle_id' => $vehicleId ?: null,
                'plate' => $vehicleId ? (string) ($plates[$vehicleId] ?? 'Vehicle #'.$vehicleId) : 'Vehicle not recorded',
            ] + $this->totals($group))->sortByDesc('net_minor')->values()->all(),
            'rows' => $rows,
        ];
    }

    /**
     * What the ledger says is owed against the unpaid earnings recorded for this fleet. The fleet account is
     * compared whole; a driver's own account is compared only when every unpaid earning of theirs is in this fleet
     * (otherwise their account also holds another fleet's money, which this fleet may not see).
     *
     * @return array{currency: string, fleet: array{ledger_minor: int, earnings_minor: int, matches: bool}, drivers: list<array{driver_id: int, name: string, ledger_minor: ?int, earnings_minor: int, matches: ?bool, note: ?string}>}
     */
    public function reconciliation(User $viewer, Fleet $fleet): array
    {
        $currency = Money::currency();
        $unpaid = $this->scope($viewer, $fleet)->where('currency', $currency)->whereIn('status', [DriverEarning::PENDING, DriverEarning::AVAILABLE]);

        $fleetEarnings = (int) (clone $unpaid)->where('payee_type', 'fleet_owner')->sum('net_minor');
        $fleetLedger = $this->ledger->balance(Account::fleetPayable($fleet->id), $currency);

        $byDriver = (clone $unpaid)->where('payee_type', 'driver')->selectRaw('driver_id, SUM(net_minor) as net')->groupBy('driver_id')->pluck('net', 'driver_id');
        $names = User::query()->whereIn('id', $byDriver->keys())->pluck('name', 'id');
        $drivers = [];

        foreach ($byDriver as $driverId => $net) {
            $elsewhere = DriverEarning::query()->where('driver_id', $driverId)->where('payee_type', 'driver')->where('currency', $currency)
                ->whereIn('status', [DriverEarning::PENDING, DriverEarning::AVAILABLE])
                ->where(fn ($q) => $q->whereNull('fleet_id')->orWhere('fleet_id', '!=', $fleet->id))
                ->exists();
            $ledger = $elsewhere ? null : $this->ledger->balance(Account::driverPayable((int) $driverId), $currency);

            $drivers[] = [
                'driver_id' => (int) $driverId,
                'name' => (string) ($names[$driverId] ?? 'Driver #'.$driverId),
                'ledger_minor' => $ledger,
                'earnings_minor' => (int) $net,
                'matches' => $ledger === null ? null : $ledger === (int) $net,
                'note' => $elsewhere ? 'Also owed earnings from another fleet; not compared here.' : null,
            ];
        }

        return [
            'currency' => $currency,
            'fleet' => ['ledger_minor' => $fleetLedger, 'earnings_minor' => $fleetEarnings, 'matches' => $fleetLedger === $fleetEarnings],
            'drivers' => $drivers,
        ];
    }

    /**
     * Commission plans published for this fleet (pricing's commission_plans with fleet_id), in force now first.
     *
     * @return Collection<int, CommissionPlan>
     */
    public function commissionPlans(Fleet $fleet): Collection
    {
        return CommissionPlan::query()->where('fleet_id', $fleet->id)->whereIn('status', ['published', 'retired'])
            ->orderByRaw('CASE WHEN retired_at IS NULL THEN 0 ELSE 1 END')->orderByDesc('effective_from')->orderByDesc('version')->get();
    }

    /**
     * The market-wide plans in force now (apply to this fleet unless a fleet plan is published).
     *
     * @return Collection<int, CommissionPlan>
     */
    public function marketPlans(): Collection
    {
        return CommissionPlan::query()->inForceAt(now())->whereNull('fleet_id')->orderBy('ride_category_id')->get();
    }

    /**
     * @return Builder<DriverEarning>
     */
    public function scope(User $viewer, Fleet $fleet): Builder
    {
        return DriverEarning::query()
            ->where('fleet_id', $fleet->id)
            ->when(! $this->access->seesDriverRecords($viewer, $fleet), fn ($q) => $q->where('driver_id', $fleet->owner_user_id));
    }

    /**
     * @param  Collection<int, DriverEarning>  $rows
     * @return array{trips: int, fare_minor: int, commission_minor: int, deductions_minor: int, incentives_minor: int, net_minor: int, cash_collected_minor: int, unpaid_minor: int, paid_minor: int}
     */
    private function totals(Collection $rows): array
    {
        return [
            'trips' => $rows->where('kind', DriverEarning::KIND_TRIP)->count(),
            'fare_minor' => (int) $rows->sum('fare_minor'),
            'commission_minor' => (int) $rows->sum('commission_minor'),
            'deductions_minor' => (int) $rows->sum('deductions_minor'),
            'incentives_minor' => (int) $rows->where('kind', DriverEarning::KIND_INCENTIVE)->sum('net_minor'),
            'net_minor' => (int) $rows->sum('net_minor'),
            'cash_collected_minor' => (int) $rows->sum('cash_collected_minor'),
            'unpaid_minor' => (int) $rows->whereIn('status', [DriverEarning::PENDING, DriverEarning::AVAILABLE])->sum('net_minor'),
            'paid_minor' => (int) $rows->where('status', DriverEarning::PAID)->sum('net_minor'),
        ];
    }
}
