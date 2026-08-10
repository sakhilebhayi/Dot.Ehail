<?php

namespace App\Providers;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Broadcast::routes() is already registered by bootstrap/app.php's
        // withRouting(channels: ...), which also requires routes/channels.php.
        // Only the private channel authorization callbacks live here.
        $this->requireChannels();
    }

    /**
     * Authenticate access to private channels.
     *
     * $rideId is deliberately not int-typed: a malformed value (e.g.
     * subscribing to "private-ride.not-a-number") would otherwise throw an
     * uncaught TypeError while PHP tries to coerce it to an `int` parameter
     * -- an unauthorized 500 instead of a clean, fail-closed 403. See
     * docs/DOT_REALTIME_STANDARD.md (Dot.Mines) §6.
     */
    protected function requireChannels(): void
    {
        /**
         * A ride's live status feed. Deliberately NOT the same rule as
         * RidePolicy::view() (which authorizes any authenticated user --
         * an intentional, documented gap for the platform-wide ops list,
         * see RidePolicy's own docblock and wiki.md §3/§7). Broadcasting a
         * ride's live status to any logged-in user would let anyone watch
         * a stranger's ride in real time; this channel is scoped to just
         * the ride's own passenger and driver instead.
         */
        Broadcast::channel('ride.{rideId}', function (User $user, $rideId) {
            if (! is_string($rideId) || ! ctype_digit($rideId)) {
                return false;
            }

            $ride = Ride::find((int) $rideId);

            if (! $ride) {
                return false;
            }

            return $user->id === $ride->passenger_id || $user->id === $ride->driver_id;
        });
    }
}
