<?php

namespace App\Http\Controllers\Ehail\Dispatch;

use App\Domain\Dispatch\AvailabilityService;
use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Dispatch\RideFeed;
use App\Enums\CancelReason;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Driver side of the dispatch API (JSON): availability, heartbeat, the
 * feed, offer answers and the trip stages.
 */
class DriverDispatchController extends Controller
{
    use DispatchJson;

    public function feed(Request $request, RideFeed $feed, Dispatcher $dispatcher): Response
    {
        $driver = $request->user();

        // Polling advances dispatch: the driver's own offer first (so a lapsed
        // offer moves on within one poll), then other due rides (rate-limited).
        if ($offer = RideOffer::where('pending_driver_id', $driver->id)->first()) {
            $dispatcher->tick(Ride::find($offer->ride_id));
        }

        $dispatcher->tick();
        $result = $feed->forDriver($driver, $this->sinceVersion($request));

        // Adaptive polling hint from RideFeed (App\Domain\Perf\PollPolicy, docs/R46_PERF.md).
        return $this->feedResponse($result, $result['poll']);
    }

    public function online(Request $request, AvailabilityService $availability, RideFeed $feed): JsonResponse
    {
        $validated = $request->validate(['vehicle_id' => ['nullable', 'integer']]);

        return $this->attempt(function () use ($request, $availability, $feed, $validated) {
            $vehicle = isset($validated['vehicle_id']) ? Vehicle::find($validated['vehicle_id']) : null;
            abort_if(isset($validated['vehicle_id']) && $vehicle === null, 422, 'Unknown vehicle.');

            $availability->goOnline($request->user(), $vehicle);

            return response()->json($feed->forDriver($request->user())['body']);
        });
    }

    public function offline(Request $request, AvailabilityService $availability, RideFeed $feed): JsonResponse
    {
        return $this->attempt(function () use ($request, $availability, $feed) {
            $availability->goOffline($request->user());

            return response()->json($feed->forDriver($request->user())['body']);
        });
    }

    public function pause(Request $request, AvailabilityService $availability, RideFeed $feed): JsonResponse
    {
        return $this->attempt(function () use ($request, $availability, $feed) {
            $availability->pause($request->user());

            return response()->json($feed->forDriver($request->user())['body']);
        });
    }

    public function resume(Request $request, AvailabilityService $availability, RideFeed $feed): JsonResponse
    {
        return $this->attempt(function () use ($request, $availability, $feed) {
            $availability->resume($request->user());

            return response()->json($feed->forDriver($request->user())['body']);
        });
    }

    public function heartbeat(Request $request, AvailabilityService $availability): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'integer', 'between:0,359'],
            'speed_ms' => ['nullable', 'numeric', 'between:0,100'],
            'accuracy_m' => ['nullable', 'numeric', 'between:0,5000'],
        ]);

        $row = $availability->heartbeat(
            $request->user(),
            isset($validated['lat']) ? (float) $validated['lat'] : null,
            isset($validated['lng']) ? (float) $validated['lng'] : null,
            isset($validated['heading']) ? (int) $validated['heading'] : null,
            isset($validated['speed_ms']) ? (float) $validated['speed_ms'] : null,
            isset($validated['accuracy_m']) ? (float) $validated['accuracy_m'] : null,
        );

        return response()->json([
            'state' => $row->state,
            'location_recorded' => $row->last_location_at !== null && $row->last_location_at->gte(now()->subSecond()),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function seen(Request $request, RideOffer $offer, Dispatcher $dispatcher): JsonResponse
    {
        abort_unless($offer->driver_id === $request->user()->id, 403);
        $dispatcher->markSeen($offer, $request->user());

        return response()->json(['ok' => true]);
    }

    public function accept(Request $request, RideOffer $offer, Dispatcher $dispatcher, RideFeed $feed): JsonResponse
    {
        return $this->attempt(function () use ($request, $offer, $dispatcher, $feed) {
            $dispatcher->offerResponse($offer, $request->user(), 'accept');

            return response()->json($feed->forDriver($request->user())['body']);
        });
    }

    public function decline(Request $request, RideOffer $offer, Dispatcher $dispatcher, RideFeed $feed): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', Rule::in(['too_far', 'destination', 'busy', 'safety', 'other'])]]);

        return $this->attempt(function () use ($request, $offer, $dispatcher, $feed, $validated) {
            $dispatcher->offerResponse($offer, $request->user(), 'decline', $validated['reason'] ?? null);

            return response()->json($feed->forDriver($request->user())['body']);
        });
    }

    /**
     * arriving | arrived | start (PIN) | complete
     */
    public function stage(Request $request, Ride $ride, string $stage, Dispatcher $dispatcher, RideFeed $feed): JsonResponse
    {
        $this->authorizeDriver($request, $ride);
        $validated = $request->validate(['pin' => ['nullable', 'string', 'max:8']]);

        $to = match ($stage) {
            'arriving' => RideStatus::DriverArriving,
            'arrived' => RideStatus::DriverArrived,
            'start' => RideStatus::InProgress,
            'complete' => RideStatus::Completed,
        };

        return $this->attempt(function () use ($ride, $request, $to, $validated, $dispatcher, $feed) {
            $dispatcher->advance($ride, $request->user(), $to, $validated['pin'] ?? null);

            return response()->json($feed->forDriver($request->user())['body'] + ['ride_status' => $ride->fresh()->status]);
        });
    }

    public function noShow(Request $request, Ride $ride, Dispatcher $dispatcher, RideFeed $feed): JsonResponse
    {
        $this->authorizeDriver($request, $ride);

        return $this->attempt(function () use ($ride, $request, $dispatcher, $feed) {
            $dispatcher->recordNoShow($ride, $request->user());

            return response()->json($feed->forDriver($request->user())['body'] + ['ride_status' => $ride->fresh()->status]);
        });
    }

    public function cancel(Request $request, Ride $ride, Dispatcher $dispatcher, RideFeed $feed): JsonResponse
    {
        $this->authorizeDriver($request, $ride);

        $validated = $request->validate([
            'reason' => ['required', 'string', Rule::in(array_map(fn (CancelReason $r) => $r->value, CancelReason::forActor(RideActor::Driver)))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->attempt(function () use ($ride, $request, $dispatcher, $feed, $validated) {
            $dispatcher->driverCancel($ride, $request->user(), CancelReason::from($validated['reason']), $validated['note'] ?? null);

            return response()->json($feed->forDriver($request->user())['body'] + ['ride_status' => $ride->fresh()->status]);
        });
    }

    private function authorizeDriver(Request $request, Ride $ride): void
    {
        abort_unless($ride->driver_id !== null && $request->user()->id === $ride->driver_id, 403, 'This ride is not assigned to you.');
    }
}
