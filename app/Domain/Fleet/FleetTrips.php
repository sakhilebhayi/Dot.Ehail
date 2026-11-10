<?php

namespace App\Domain\Fleet;

use App\Enums\RideStatus;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Trip history of a fleet: rides its drivers held or its vehicles carried. Each row keeps only what a trip record
 * needs: when, status, driver, vehicle, the passenger's first name, distance and fare. Pickup and drop-off
 * addresses and positions are not shown to fleets (they are the passenger's places, POPIA minimisation, D-1206).
 */
final class FleetTrips
{
    public function __construct(private readonly FleetAccess $access) {}

    /**
     * @param  array{status?: ?string, driver?: ?int, vehicle?: ?int}  $filters
     */
    public function page(User $viewer, Fleet $fleet, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $driverIds = $this->access->driverUserIds($viewer, $fleet);
        $vehicleIds = $this->access->vehicleQuery($viewer, $fleet)->pluck('id')->all();

        $query = Ride::query()
            ->where(fn ($q) => $q->whereIn('driver_id', $driverIds)->orWhereIn('vehicle_id', $vehicleIds))
            ->whereNotNull('driver_id')
            ->when($filters['status'] ?? null, fn ($q, $status) => match ($status) {
                'completed' => $q->where('status', RideStatus::Completed->value),
                'cancelled' => $q->whereIn('status', [RideStatus::CancelledByDriver->value, RideStatus::CancelledByPassenger->value, RideStatus::CancelledByOperator->value, RideStatus::NoShow->value]),
                'active' => $q->whereIn('status', RideStatus::values(RideStatus::driverHoldingStates())),
                default => $q,
            })
            ->when($filters['driver'] ?? null, fn ($q, $driver) => $q->where('driver_id', in_array((int) $driver, $driverIds, true) ? (int) $driver : 0))
            ->when($filters['vehicle'] ?? null, fn ($q, $vehicle) => $q->where('vehicle_id', in_array((int) $vehicle, $vehicleIds, true) ? (int) $vehicle : 0))
            ->orderByDesc('id');

        $page = $query->paginate($perPage, ['id', 'status', 'driver_id', 'vehicle_id', 'passenger_id', 'requested_at', 'completed_at', 'created_at', 'final_fare_minor', 'quoted_total_minor', 'final_fare', 'estimated_fare', 'route_distance_m', 'distance_km', 'currency'])
            ->withQueryString();

        $names = User::query()->whereIn('id', $page->getCollection()->pluck('driver_id')->merge($page->getCollection()->pluck('passenger_id'))->unique())->pluck('name', 'id');
        $plates = Vehicle::query()->whereIn('id', $page->getCollection()->pluck('vehicle_id')->filter()->unique())->pluck('plate_number', 'id');

        $page->setCollection($page->getCollection()->map(fn (Ride $ride) => [
            'id' => $ride->id,
            'status' => $ride->status,
            'at' => $ride->completed_at ?? $ride->requested_at ?? $ride->created_at,
            'driver' => (string) ($names[$ride->driver_id] ?? 'Driver'),
            'vehicle' => $ride->vehicle_id ? (string) ($plates[$ride->vehicle_id] ?? '') : null,
            'passenger_first_name' => self::firstName($names[$ride->passenger_id] ?? null),
            'distance_m' => $ride->route_distance_m ?? ($ride->distance_km !== null ? (int) round(((float) $ride->distance_km) * 1000) : null),
            'fare_minor' => $ride->final_fare_minor ?? ($ride->final_fare !== null ? (int) round(((float) $ride->final_fare) * 100) : null),
            'fare_is_final' => $ride->final_fare_minor !== null || $ride->final_fare !== null,
            'currency' => $ride->currency ?? 'ZAR',
        ]));

        return $page;
    }

    public static function firstName(?string $name): string
    {
        $first = Str::of((string) $name)->trim()->explode(' ')->first();

        return $first !== null && $first !== '' ? (string) $first : 'Passenger';
    }
}
