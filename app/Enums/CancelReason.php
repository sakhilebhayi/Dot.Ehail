<?php

namespace App\Enums;

/**
 * Why a passenger cancelled, or why a driver backed out of an accepted ride.
 * Each reason belongs to one side (or both); the cancel endpoint rejects a
 * reason that does not fit the person using it.
 */
enum CancelReason: string
{
    case ChangedPlans = 'changed_plans';
    case WaitedTooLong = 'waited_too_long';
    case WrongAddress = 'wrong_address';
    case FoundOtherRide = 'found_other_ride';
    case PassengerNoShow = 'passenger_no_show';
    case VehicleProblem = 'vehicle_problem';
    case SafetyConcern = 'safety_concern';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ChangedPlans => 'My plans changed',
            self::WaitedTooLong => 'The wait is too long',
            self::WrongAddress => 'Wrong address entered',
            self::FoundOtherRide => 'I found another ride',
            self::PassengerNoShow => 'Passenger did not show up',
            self::VehicleProblem => 'Vehicle problem',
            self::SafetyConcern => 'Safety concern',
            self::Other => 'Something else',
        };
    }

    /**
     * @return list<RideActor>
     */
    public function actors(): array
    {
        return match ($this) {
            self::ChangedPlans, self::WaitedTooLong, self::WrongAddress, self::FoundOtherRide => [RideActor::Passenger],
            self::PassengerNoShow, self::VehicleProblem => [RideActor::Driver],
            self::SafetyConcern, self::Other => [RideActor::Passenger, RideActor::Driver, RideActor::Operator],
        };
    }

    /**
     * @return list<self>
     */
    public static function forActor(RideActor $actor): array
    {
        return array_values(array_filter(self::cases(), fn (self $r) => in_array($actor, $r->actors(), true)));
    }
}
