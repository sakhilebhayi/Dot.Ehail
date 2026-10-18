<?php

namespace App\Enums;

/**
 * Who is asking for a ride to change state. RideStatus's transition table
 * names the actors allowed to take each edge.
 */
enum RideActor: string
{
    case Passenger = 'passenger';
    case Driver = 'driver';
    case Operator = 'operator';
    case System = 'system';
}
