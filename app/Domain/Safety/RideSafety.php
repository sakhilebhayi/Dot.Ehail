<?php

namespace App\Domain\Safety;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Small shared rules about rides for the safety domain. Status values cover both the current
 * lifecycle (accepted, en_route, arrived) and the R-46 names dispatch introduces
 * (driver_assigned, driver_arriving, driver_arrived), so this works before and after that merge.
 */
final class RideSafety
{
    /** A driver is attached and the trip has not ended. */
    public const ACTIVE = ['accepted', 'en_route', 'arrived', 'in_progress', 'driver_assigned', 'driver_arriving', 'driver_arrived'];

    public const TERMINAL = ['completed', 'cancelled', 'cancelled_by_passenger', 'cancelled_by_driver', 'cancelled_by_operator', 'expired', 'no_show', 'failed'];

    public static function isActive(Ride $ride): bool
    {
        return in_array($ride->status, self::ACTIVE, true) && $ride->driver_id !== null;
    }

    public static function isTerminal(Ride $ride): bool
    {
        return in_array($ride->status, self::TERMINAL, true);
    }

    /** When the trip ended, or null while it has not. */
    public static function endedAt(Ride $ride): ?Carbon
    {
        if (! self::isTerminal($ride)) {
            return null;
        }

        return $ride->completed_at ?? $ride->cancelled_at ?? $ride->expired_at ?? $ride->updated_at;
    }

    /** 'passenger', 'driver' or null. Operators are not parties. */
    public static function roleOf(Ride $ride, User $user): ?string
    {
        return match (true) {
            $user->id === $ride->passenger_id => 'passenger',
            $ride->driver_id !== null && $user->id === $ride->driver_id => 'driver',
            default => null,
        };
    }

    public static function otherParty(Ride $ride, User $user): ?int
    {
        return match (self::roleOf($ride, $user)) {
            'passenger' => $ride->driver_id,
            'driver' => $ride->passenger_id,
            default => null,
        };
    }

    public static function firstName(?string $name, string $fallback = 'Not given'): string
    {
        $first = trim((string) strtok(trim((string) $name), " \t"));

        return $first !== '' ? mb_substr($first, 0, 40) : $fallback;
    }
}
