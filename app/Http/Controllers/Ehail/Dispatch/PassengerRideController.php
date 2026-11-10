<?php

namespace App\Http\Controllers\Ehail\Dispatch;

use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\RideFeed;
use App\Domain\Rides\IdempotentRequests;
use App\Domain\Rides\RideRequestService;
use App\Domain\Rides\RideStateMachine;
use App\Domain\Rides\ScheduledRides;
use App\Domain\Rides\TransitionOptions;
use App\Domain\Rides\TripChanges;
use App\Enums\CancelReason;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passenger side of the dispatch API (JSON). Requests are created from a
 * server-issued fare quote id only; the client never sends a fare.
 */
class PassengerRideController extends Controller
{
    use DispatchJson;

    public function store(Request $request, RideRequestService $service, IdempotentRequests $idempotency, RideFeed $feed): JsonResponse
    {
        $validated = $request->validate([
            'quote_id' => ['required', 'string', 'max:64'],
            'payment_method' => ['nullable', 'string', 'max:24'],
            'payment_ref' => ['nullable', 'string', 'max:64'],
            'scheduled_for' => ['nullable', 'date'],
            'pickup_address' => ['nullable', 'string', 'max:255'],
            'dropoff_address' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        return $this->attempt(function () use ($validated, $user, $request, $service, $idempotency, $feed) {
            $result = $idempotency->run($user, 'ride.request', $request->header('Idempotency-Key'), $validated, function () use ($validated, $user, $service, $feed) {
                $ride = $service->request(
                    $user,
                    $validated['quote_id'],
                    $validated['payment_method'] ?? null,
                    $validated['payment_ref'] ?? null,
                    isset($validated['scheduled_for']) ? Carbon::parse($validated['scheduled_for']) : null,
                    ['pickup' => $validated['pickup_address'] ?? null, 'dropoff' => $validated['dropoff_address'] ?? null],
                );

                return [201, $feed->forPassenger($ride->fresh())['body'], $ride->id];
            });

            return response()->json($result['body'], $result['status'])
                ->header('Idempotent-Replayed', $result['replayed'] ? 'true' : 'false');
        });
    }

    /**
     * GET the passenger feed. Polling the feed also advances this ride's
     * dispatch (and, rate-limited, other due rides), D-2.
     */
    public function feed(Request $request, Ride $ride, RideFeed $feed, Dispatcher $dispatcher): Response
    {
        $this->authorizePassenger($request, $ride);

        if ($ride->statusEnum()->isAwaitingDriver() && $ride->statusEnum() !== RideStatus::Scheduled) {
            $dispatcher->tick($ride);
        }

        $dispatcher->tick();
        $ride->refresh();

        return $this->feedResponse($feed->forPassenger($ride, $this->sinceVersion($request)), $feed->passengerPollSeconds($ride->statusEnum()));
    }

    /**
     * POST a change to the trip at the passenger's request (new destination or an extra stop), D-712.
     */
    public function changeTrip(Request $request, Ride $ride, TripChanges $changes, RideFeed $feed): JsonResponse
    {
        $this->authorizePassenger($request, $ride);

        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(array_keys(TripChanges::KINDS))],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->attempt(function () use ($ride, $request, $changes, $validated, $feed) {
            $ride = $changes->record($ride, $request->user(), $validated['kind'], (float) $validated['lat'], (float) $validated['lng'], $validated['address'] ?? null);

            return response()->json($feed->forPassenger($ride)['body']);
        });
    }

    public function cancel(Request $request, Ride $ride, RideStateMachine $machine, RideFeed $feed): JsonResponse
    {
        $this->authorizePassenger($request, $ride);

        $validated = $request->validate([
            'reason' => ['required', 'string', Rule::in(array_map(fn (CancelReason $r) => $r->value, CancelReason::forActor(RideActor::Passenger)))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->attempt(function () use ($ride, $request, $machine, $validated, $feed) {
            $ride = $machine->transition($ride, RideStatus::CancelledByPassenger, RideActor::Passenger, $request->user(),
                new TransitionOptions(reason: $validated['reason'], note: $validated['note'] ?? null));

            return response()->json($feed->forPassenger($ride)['body']);
        });
    }

    public function reschedule(Request $request, Ride $ride, ScheduledRides $scheduled, RideFeed $feed): JsonResponse
    {
        $this->authorizePassenger($request, $ride);
        $validated = $request->validate(['scheduled_for' => ['required', 'date']]);

        return $this->attempt(fn () => response()->json(
            $feed->forPassenger($scheduled->reschedule($ride, $request->user(), Carbon::parse($validated['scheduled_for'])))['body'],
        ));
    }

    public function acceptRequote(Request $request, Ride $ride, ScheduledRides $scheduled, RideFeed $feed): JsonResponse
    {
        $this->authorizePassenger($request, $ride);

        return $this->attempt(fn () => response()->json(
            $feed->forPassenger($scheduled->acceptRequote($ride, $request->user()))['body'],
        ));
    }

    private function authorizePassenger(Request $request, Ride $ride): void
    {
        abort_unless($request->user()->id === $ride->passenger_id, 403, 'This is not your ride.');
    }
}
