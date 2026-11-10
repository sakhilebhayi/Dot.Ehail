<?php

namespace App\Http\Controllers\Money;

use App\Domain\Earnings\EarningsService;
use App\Domain\Earnings\Models\DriverEarning;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Fleet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fleet owner view of the earnings of drivers in a fleet they own (approved fleets only).
 */
class FleetMoneyController extends Controller
{
    public function earnings(Request $request, Fleet $fleet, EarningsService $earnings): JsonResponse
    {
        Gate::authorize('money.view-fleet-earnings', $fleet);
        $data = $this->period($request);

        return response()->json(['fleet_id' => $fleet->id] + $earnings->forDrivers($this->driverIds($fleet), $data['period'], $data['date'] ?? null));
    }

    public function earningsCsv(Request $request, Fleet $fleet, EarningsService $earnings): StreamedResponse
    {
        Gate::authorize('money.view-fleet-earnings', $fleet);
        $data = $this->period($request);
        $rows = $earnings->csvRows(DriverEarning::query()->whereIn('driver_id', $this->driverIds($fleet)), $data['period'], $data['date'] ?? null);

        return DriverMoneyController::csv($rows, 'fleet-'.$fleet->id.'-earnings.csv');
    }

    /**
     * @return array<int, int>
     */
    private function driverIds(Fleet $fleet): array
    {
        return DriverProfile::withoutGlobalScope('user')->where('fleet_id', $fleet->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
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
}
