<?php

namespace App\Http\Controllers\Driver;

use App\Domain\Business\RiderDetails;
use App\Domain\Dispatch\Contracts\DriverLocator;
use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Dispatch\Geo;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Dispatch\RideFeed;
use App\Domain\Pricing\Earnings\EarningsSplitter;
use App\Domain\Pricing\Earnings\Split;
use App\Domain\Pricing\Models\CommissionPlan;
use App\Domain\Pricing\Models\RideFare;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Driver\Support\TripSummary;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Services\Ehail\InvalidRideTransition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The driver app's own JSON endpoints. Everything else the app calls belongs to its domain (dispatch feed and
 * stages, geo location ingest, money cash confirmation, safety SOS). Each endpoint answers only for the signed-in
 * driver's own offer or ride; anything else is a 404, so the existence of other drivers' rides is not revealed.
 */
class DriverApiController extends Controller
{
    /**
     * The earnings breakdown for an offer, before the driver answers it: the pricing domain's split of the ride's
     * quoted fare under the commission plan that applies to this driver. Null figures mean "not configured".
     */
    public function offerEarnings(Request $request, RideOffer $offer, EarningsSplitter $splitter): JsonResponse
    {
        abort_unless($offer->driver_id === $request->user()->id, 404);

        $ride = Ride::query()->findOrFail($offer->ride_id);
        $split = $splitter->estimateForRide($ride, $request->user()->id);

        if ($split === null) {
            return response()->json([
                'available' => false,
                'currency' => $ride->currency ?? 'ZAR',
                'fare_minor' => $ride->quoted_total_minor,
                'note' => 'No fare breakdown is stored for this ride, so commission and net earnings cannot be shown.',
            ]);
        }

        return response()->json([
            'available' => true,
            'kind' => 'estimate',
            'currency' => $split->currency,
            'fare_minor' => $split->fareMinor,
            'driver_gross_minor' => $split->driverGrossMinor,
            'tolls_minor' => $split->passThroughMinor,
            // The base the commission is charged on and the parts of the fare the platform keeps (booking fee, zone
            // fees, tax, platform-funded promotions as negatives), so the offer card can list every line and they
            // add up: fare - kept - commission = net (D-2101).
            'commissionable_minor' => $split->commissionableMinor,
            'kept_by_platform' => $this->keptByPlatform($ride, $split),
            'commission_rule' => $split->plan === null ? null : [
                'percent_bp' => (int) $split->plan->percent_bp,
                'fixed_minor' => (int) $split->plan->fixed_minor,
                'min_minor' => $split->plan->min_commission_minor,
                'max_minor' => $split->plan->max_commission_minor,
            ],
            'commission_minor' => $split->commissionMinor,
            'deductions' => array_values(array_map(fn (array $d) => [
                'code' => $d['code'], 'label' => $d['label'], 'amount_minor' => (int) $d['amount_minor'], 'detail' => $d['detail'] ?? null,
            ], $split->deductions)),
            'net_minor' => $split->driverNetMinor,
            'plan' => $split->plan === null ? null : ['name' => $split->plan->name, 'is_example' => (bool) $split->plan->is_example],
            'note' => $split->plan === null ? 'No commission plan is configured for this market yet, so your net earnings cannot be shown.' : null,
        ]);
    }

    /**
     * The fare components that are neither commissionable nor tolls (the platform's share before commission), each
     * with the pricing label. When the components do not explain the whole difference (rounding in an old snapshot),
     * the rest is one more line, so the list always sums to fare - driver gross.
     *
     * @return list<array{code: string, label: string, amount_minor: int}>
     */
    private function keptByPlatform(Ride $ride, Split $split): array
    {
        $codes = [...($split->plan?->commissionableCodes() ?? CommissionPlan::DEFAULT_COMMISSIONABLE), 'tolls'];
        $components = RideFare::query()->where('ride_id', $ride->id)->value('estimated_components') ?? [];
        $matches = fn (string $code) => collect($codes)->contains(fn (string $c) => $code === $c || str_starts_with($code, $c.':'));

        $lines = collect(is_array($components) ? $components : [])
            ->filter(fn ($c) => is_array($c) && (int) ($c['amount_minor'] ?? 0) !== 0 && ! $matches((string) ($c['code'] ?? '')))
            ->map(fn (array $c) => ['code' => (string) $c['code'], 'label' => (string) ($c['label'] ?? $c['code']), 'amount_minor' => (int) $c['amount_minor']])
            ->values()->all();

        $rest = $split->fareMinor - $split->driverGrossMinor - array_sum(array_column($lines, 'amount_minor'));

        if ($rest !== 0) {
            $lines[] = ['code' => 'other', 'label' => 'Other platform amounts', 'amount_minor' => $rest];
        }

        return $lines;
    }

    /**
     * "I've arrived", with a distance check against the driver's last reported position (dispatch's index, fed by
     * the phone). Within tolerance it goes straight through; farther away, or with no recent position, the driver
     * must confirm, and that confirmation is audited with the distance. The transition itself is dispatch's.
     */
    public function arrived(Request $request, Ride $ride, Dispatcher $dispatcher, DriverLocator $locator, RideFeed $feed): JsonResponse
    {
        $driver = $request->user();
        abort_unless($ride->driver_id !== null && $ride->driver_id === $driver->id, 404);

        $validated = $request->validate([
            'confirm_far' => ['nullable', 'boolean'],
            'accuracy_m' => ['nullable', 'numeric', 'between:0,5000'],
        ]);

        $status = $ride->statusEnum();
        if (! in_array($status, [RideStatus::DriverAssigned, RideStatus::DriverArriving], true)) {
            return response()->json(['error' => 'invalid_transition', 'message' => 'This ride is not on its way to the pickup.'], 409);
        }

        $check = $this->arrivalCheck($ride, $locator, (float) ($validated['accuracy_m'] ?? 0));

        if (! $check['within'] && ! ($validated['confirm_far'] ?? false)) {
            return response()->json([
                'error' => $check['distance_m'] === null ? 'position_unknown' : 'too_far',
                'message' => $check['distance_m'] === null
                    ? 'We have no recent position for your phone, so we cannot see that you are at the pickup.'
                    : 'Your phone places you about '.$this->distanceWords($check['distance_m']).' from the pickup.',
            ] + $check, 409);
        }

        try {
            if ($status === RideStatus::DriverAssigned) {
                $dispatcher->advance($ride, $driver, RideStatus::DriverArriving);
                $ride->refresh();
            }

            $dispatcher->advance($ride, $driver, RideStatus::DriverArrived);
        } catch (DispatchRefused $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], $e->status);
        } catch (InvalidRideTransition $e) {
            return response()->json(['error' => 'invalid_transition', 'message' => $e->getMessage()], 409);
        }

        if (! $check['within']) {
            AuditLog::record('driver.arrived_outside_tolerance', $driver, $ride, [
                'distance_m' => $check['distance_m'], 'tolerance_m' => $check['tolerance_m'], 'fix_age_s' => $check['fix_age_s'],
            ]);
        }

        return response()->json($feed->forDriver($driver)['body'] + ['arrival_check' => $check]);
    }

    /**
     * Who to pick up on a business ride booked for someone else (the booker is the passenger of record). Only for
     * the assigned driver while the ride holds the driver; the phone is shown masked, as in the business portal
     * (docs/R46_BUSINESS.md): the booker's app and support hold the full number.
     */
    public function rider(Request $request, Ride $ride, RiderDetails $riders): JsonResponse
    {
        abort_unless($ride->driver_id !== null && $ride->driver_id === $request->user()->id && $ride->statusEnum()->holdsDriver(), 404);

        $details = $riders->forRide($ride->id);

        if ($details === null) {
            return response()->json(['on_behalf' => false]);
        }

        $digits = preg_replace('/\D/', '', (string) $details['phone']);

        return response()->json([
            'on_behalf' => true,
            'guest' => $details['guest'],
            'name' => $details['name'],
            'account' => $details['account'],
            'phone_masked' => strlen($digits) >= 4 ? '•••• '.substr($digits, -4) : null,
        ]);
    }

    public function summary(Request $request, Ride $ride): JsonResponse
    {
        abort_unless($ride->driver_id !== null && $ride->driver_id === $request->user()->id, 404);

        return response()->json(TripSummary::for($ride));
    }

    /**
     * @return array{within: bool, distance_m: ?int, tolerance_m: int, fix_age_s: ?int}
     */
    private function arrivalCheck(Ride $ride, DriverLocator $locator, float $accuracy): array
    {
        $tolerance = (int) config('ehail_driver.arrival.tolerance_m') + (int) min(max(0, $accuracy), (int) config('ehail_driver.arrival.accuracy_allowance_cap_m'));

        // A pre-R-46 ride with an address only has no point to measure against: nothing to check.
        if ($ride->pickup_lat === null || $ride->pickup_lng === null) {
            return ['within' => true, 'distance_m' => null, 'tolerance_m' => $tolerance, 'fix_age_s' => null];
        }

        $position = $locator->latest($ride->driver_id);
        $age = $position ? (int) $position->recordedAt->diffInSeconds(now(), true) : null;

        if ($position === null || $age > (int) config('ehail_driver.arrival.max_fix_age_s')) {
            return ['within' => false, 'distance_m' => null, 'tolerance_m' => $tolerance, 'fix_age_s' => $age];
        }

        $distance = Geo::distanceM($position->lat, $position->lng, (float) $ride->pickup_lat, (float) $ride->pickup_lng);

        return ['within' => $distance <= $tolerance, 'distance_m' => $distance, 'tolerance_m' => $tolerance, 'fix_age_s' => $age];
    }

    private function distanceWords(int $metres): string
    {
        return $metres >= 1000 ? number_format($metres / 1000, 1).' km' : ((int) (round($metres / 10) * 10)).' m';
    }
}
