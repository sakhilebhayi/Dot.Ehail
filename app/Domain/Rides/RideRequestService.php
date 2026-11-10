<?php

namespace App\Domain\Rides;

use App\Domain\Dispatch\Contracts\FareQuoteGateway;
use App\Domain\Dispatch\Contracts\PaymentMethodResolver;
use App\Domain\Dispatch\Data\LockedQuote;
use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Dispatch\Exceptions\PaymentMethodUnavailable;
use App\Domain\Rides\Events\RideRequested;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a ride from a server-issued fare quote (never from a client fare):
 * the quote must exist, belong to the passenger, be unexpired and unused.
 * One active ride per passenger (checked here and enforced by the
 * rides.active_passenger_id unique column). A 4-digit trip PIN is issued.
 * On-demand rides start dispatch at once; scheduled rides wait in
 * `scheduled` (ScheduledRides moves them on at the allocation lead time).
 */
class RideRequestService
{
    public function __construct(
        private readonly FareQuoteGateway $quotes,
        private readonly PaymentMethodResolver $payments,
        private readonly Dispatcher $dispatcher,
        private readonly RideTimeline $timeline,
        private readonly RideStateMachine $machine,
        private readonly TripPin $pin,
    ) {}

    /**
     * @param  array{pickup?: ?string, dropoff?: ?string}  $addresses  display labels the passenger chose (geocoded in the app); the quote holds only coordinates
     */
    public function request(User $passenger, string $quoteId, ?string $paymentKind = null, ?string $paymentRef = null, ?CarbonInterface $scheduledFor = null, array $addresses = []): Ride
    {
        if (! $this->quotes->available()) {
            throw new DispatchRefused('pricing_unavailable', 'Fares are not available right now, so rides cannot be requested. Please try again later.', 503);
        }

        $quote = $this->quotes->findForPassenger($quoteId, $passenger);

        if ($quote === null || $quote->passengerId !== $passenger->id || $quote->expiresAt->isPast()) {
            throw new DispatchRefused('quote_invalid', 'That fare quote has expired or is not yours. Get a new quote and try again.', 422);
        }

        if (Ride::where('fare_quote_id', $quote->id)->exists()) {
            throw new DispatchRefused('quote_used', 'That fare quote was already used for a ride. Get a new quote.', 409);
        }

        try {
            $payment = $this->payments->resolve($passenger, $paymentKind, $paymentRef);
        } catch (PaymentMethodUnavailable $e) {
            throw new DispatchRefused('payment_method_unavailable', $e->getMessage(), 422);
        }

        if ($scheduledFor !== null) {
            ScheduledRides::assertBookable($scheduledFor);
        } elseif (Ride::where('active_passenger_id', $passenger->id)->exists()) {
            throw new DispatchRefused('active_ride_exists', 'You already have a ride in progress. Finish or cancel it first.', 409);
        }

        try {
            $ride = DB::transaction(function () use ($passenger, $quote, $payment, $scheduledFor, $addresses) {
                $ride = Ride::create($this->attributes($passenger, $quote, $scheduledFor, $addresses) + [
                    'payment_method_kind' => $payment->kind,
                    'payment_method_ref' => $payment->reference,
                ]);

                $ride->forceFill($this->pin->attributesFor($ride))->save();

                // Inside the same transaction: a refused binding or payment choice rolls the ride back (D-701).
                $this->quotes->bind($quote, $passenger, $ride);

                try {
                    $this->payments->attach($ride, $passenger, $payment);
                } catch (PaymentMethodUnavailable $e) {
                    throw new DispatchRefused('payment_method_unavailable', $e->getMessage(), 422);
                }

                $this->timeline->append($ride, 'created', RideActor::Passenger, $passenger->id,
                    $scheduledFor ? 'scheduled_booking' : 'on_demand_request',
                    ['quote_id' => $quote->id, 'total_minor' => $quote->totalMinor, 'currency' => $quote->currency],
                    null, $ride->status, 'created');

                return $ride;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new DispatchRefused('active_ride_exists', 'You already have a ride in progress. Finish or cancel it first.', 409);
        }

        if ($scheduledFor !== null) {
            return $ride;
        }

        $this->machine->emit(new RideRequested($ride->id, $passenger->id, RideActor::Passenger->value, now(), 'on_demand_request', null, RideStatus::Requested->value));

        return $this->dispatcher->request($ride);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(User $passenger, LockedQuote $quote, ?CarbonInterface $scheduledFor, array $addresses = []): array
    {
        $label = fn (?string $value, string $fallback) => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 255) : $fallback;

        // rides.vehicle_type is still the pre-R-46 enum column; a pricing category that
        // is not one of its values keeps 'standard' there and is matched by category_code.
        $tiers = array_keys(config('ehail.vehicle_tiers'));

        return [
            'passenger_id' => $passenger->id,
            'status' => $scheduledFor ? RideStatus::Scheduled->value : RideStatus::Requested->value,
            'scheduled_for' => $scheduledFor,
            'requested_at' => $scheduledFor ? null : now(),
            'pickup_address' => $label($addresses['pickup'] ?? null, $quote->pickupAddress),
            'pickup_lat' => $quote->pickupLat,
            'pickup_lng' => $quote->pickupLng,
            'dropoff_address' => $label($addresses['dropoff'] ?? null, $quote->dropoffAddress),
            'dropoff_lat' => $quote->dropoffLat,
            'dropoff_lng' => $quote->dropoffLng,
            'vehicle_type' => in_array($quote->category, $tiers, true) ? $quote->category : 'standard',
            'category_code' => $quote->category,
            'fare_quote_id' => $quote->id,
            'quote_snapshot' => $quote->snapshot(),
            'quoted_total_minor' => $quote->totalMinor,
            'currency' => $quote->currency,
            // The decimal column stays for history and the pre-R-46 pages (D-3 mirror).
            'estimated_fare' => $quote->totalMinor / 100,
            'distance_km' => $quote->routeDistanceM !== null ? round($quote->routeDistanceM / 1000, 2) : null,
            'route_distance_m' => $quote->routeDistanceM,
            'route_duration_s' => $quote->routeDurationS,
        ];
    }
}
