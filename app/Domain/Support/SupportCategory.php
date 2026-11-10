<?php

namespace App\Domain\Support;

enum SupportCategory: string
{
    case InTripAssistance = 'in_trip_assistance';
    case PostTrip = 'post_trip';
    case LostProperty = 'lost_property';
    case FareDispute = 'fare_dispute';
    case PaymentIssue = 'payment_issue';
    case DriverConduct = 'driver_conduct';
    case PassengerConduct = 'passenger_conduct';
    case SafetyIncident = 'safety_incident';
    case AccountProblem = 'account_problem';

    public function label(): string
    {
        return match ($this) {
            self::InTripAssistance => 'Help during a trip',
            self::PostTrip => 'After a trip',
            self::LostProperty => 'Lost property',
            self::FareDispute => 'Fare dispute',
            self::PaymentIssue => 'Payment issue',
            self::DriverConduct => 'Driver conduct',
            self::PassengerConduct => 'Passenger conduct',
            self::SafetyIncident => 'Safety incident',
            self::AccountProblem => 'Account problem',
        };
    }

    public function needsRide(): bool
    {
        return in_array($this, [self::InTripAssistance, self::PostTrip, self::LostProperty, self::FareDispute, self::DriverConduct, self::PassengerConduct], true);
    }

    public function defaultPriority(): SupportPriority
    {
        return SupportPriority::from((string) config('ehail_safety.support.default_priority.'.$this->value, 'normal'));
    }
}
