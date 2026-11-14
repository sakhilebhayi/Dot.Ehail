<?php

namespace App\Support\Ui;

use App\Models\User;
use Dot\Ui\PlatformSwitcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The "Dot Apps" switcher (shared dot-ui) in every signed-in shell, built so it can never get in a trip's way.
 *
 * The switcher's model comes from InfoDot (cached ten minutes, a failure one minute, a one second read timeout here)
 * and the component never throws. On top of that, the screens that run a trip (the rider's booking and live trip, the
 * driver's console and trip, every safety page, and the operator's live board, rides and safety desk) NEVER ask
 * InfoDot at all: they print the switcher only from an answer an ordinary page already cached, and print nothing
 * when there is none. A slow or dead InfoDot therefore cannot delay a booking, a driver ping, an SOS or a dispatch
 * decision by a single millisecond, and the switcher is only ever missing, never blocking.
 */
final class AppsSwitcher
{
    public const PLATFORM = 'ehail';

    /** Route names (Str::is patterns) that are live: cache only, never an InfoDot call. */
    public const LIVE_ROUTES = [
        // Rider: booking and fares, the trip as it runs, safety, the places and overview APIs the trip screens read.
        'ride.home', 'ride.trip', 'ride.safety', 'ride.safety.*', 'ride.api.*', 'ride.support*',
        // Driver: the console (the driver feed and pings live here), a trip, safety.
        'drive.home', 'drive.trips.show', 'drive.safety', 'drive.safety.*', 'drive.api.*',
        // Every safety page, trip sharing and the map endpoints.
        'safety.*', 'geo.*',
        // The older ride pages: booking, the driver board, a ride in progress.
        'rides.create', 'rides.available', 'rides.show', 'rides.pay*',
        // Operations: the live board, the ride desk and a ride, the whole safety desk, status.
        'ops.live', 'ops.live.*', 'ops.rides.show', 'ops.safety', 'ops.safety.*', 'ops.status',
        // The dispatch endpoints (feeds, offers) are JSON and render no shell; listed so a future page there stays live.
        'ehail.dispatch.*',
    ];

    public static function isLive(?string $routeName): bool
    {
        return $routeName !== null && $routeName !== '' && Str::is(self::LIVE_ROUTES, $routeName);
    }

    /** The current company's InfoDot uuid, or null for a personal workspace or no team (the component's personal state). */
    public static function companyFor(?User $user): ?string
    {
        $team = $user?->currentTeam;

        if ($team === null || $team->personal_team || blank($team->infodot_team_uuid)) {
            return null;
        }

        return (string) $team->infodot_team_uuid;
    }

    /**
     * The view model for <x-dot.switcher :model>, or null when nothing should be printed (signed out, a live screen
     * with no cached answer, any failure).
     *
     * @return array<string, mixed>|null
     */
    public static function model(?User $user, ?string $routeName): ?array
    {
        if ($user === null) {
            return null;
        }

        try {
            $switcher = PlatformSwitcher::fromConfig(self::PLATFORM);
            $company = self::companyFor($user);

            // A personal workspace asks InfoDot nothing, so it is safe everywhere.
            if ($company === null) {
                return $switcher->for(null);
            }

            if (self::isLive($routeName)) {
                $cached = app('cache')->store()->get($switcher->cacheKeyFor(strtolower($company)));

                return is_array($cached) && array_key_exists('ok', $cached) ? $switcher->for($company) : null;
            }

            return $switcher->for($company);
        } catch (\Throwable $e) {
            Log::debug('Dot Apps switcher skipped.', ['reason' => $e::class]);

            return null;
        }
    }
}
