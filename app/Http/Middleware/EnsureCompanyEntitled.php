<?php

namespace App\Http\Middleware;

use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\EntitlementClient;
use App\Support\EntitlementRefusal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in area honours the company's entitlement (R-50), without ever putting a person on a trip at risk.
 *
 * InfoDot decides whether a company holds Dot.Ehail. A person who is already signed in must stop starting new
 * things when that ends, so each request in the signed-in area asks (through a ten-minute cache) whether the
 * person's current company still holds the platform. A clear "no" answers with the plain refusal page and HTTP 403
 * (JSON 403 for scripts, abort(403) for Livewire updates). Anything else, including every kind of failure, lets the
 * request through exactly as before.
 *
 * Safety comes before the check, in three layers, and a refusal can never reach any of them:
 *  1. Routes that keep a trip, its safety or its money working are never checked (EXEMPT, the operator console, and
 *     the exceptions below): SOS and the whole safety flow, driver location pings and the driver's poll, the live
 *     trip feed and tracking, the driver's trip stages and offers, going offline, paying for a ride, cash received,
 *     and the operator console (dispatch, safety incidents, refunds and settlement).
 *  2. A person with a trip in progress (a ride that is requested, searching or held by a driver, as passenger or
 *     as driver) is never refused on any route until it ends.
 *  3. Webhooks, health checks, sign-in/out, the handoff receiver and public pages (trip-share links) are not in the
 *     checked group at all.
 * What is refused is a new session's pages and actions: booking a ride, going online, the wallet and account pages.
 */
class EnsureCompanyEntitled
{
    /** Route names (Str::is patterns) that must keep working for a person whose company is refused. */
    private const EXEMPT = [
        // The person's own account and company switching.
        'current-team.update', 'profile.show', 'terms.show', 'policy.show', 'team-invitations.accept', 'account.data-export',
        // Safety: SOS, incidents, trip sharing, check-ins, trusted contacts, the safety pages.
        'safety.*', 'ride.safety', 'ride.safety.*', 'drive.safety', 'drive.safety.*',
        // Dispatch: the driver's poll, heartbeat, going offline, pausing, trip stages, offers; a rider's live feed,
        // cancelling, re-quote and changes to a trip that already exists. Not exempt: booking a new ride, going online.
        'ehail.dispatch.driver.feed', 'ehail.dispatch.driver.sync', 'ehail.dispatch.driver.heartbeat',
        'ehail.dispatch.driver.offline', 'ehail.dispatch.driver.pause', 'ehail.dispatch.driver.resume',
        'ehail.dispatch.driver.rides.*', 'ehail.dispatch.offers.*',
        'ehail.dispatch.rides.feed', 'ehail.dispatch.rides.cancel', 'ehail.dispatch.rides.requote.accept',
        'ehail.dispatch.rides.trip-change', 'ehail.dispatch.rides.reschedule',
        'rides.accept', 'rides.advance', 'rides.cancel',
        'drive.api.*', 'ride.api.rides.overview',
        // Location pings and live tracking, and the map settings the trip screens read.
        'geo.driver.location', 'geo.rides.driver-location', 'geo.config', 'geo.route',
        // Money that settles a ride: paying, the card return, the payment choice, cash received.
        'rides.pay', 'rides.pay.callback', 'money.rides.pay-now', 'money.rides.choice', 'money.payment-due',
        'money.cards.callback', 'money.driver.cash-received',
    ];

    /** Session key: redeem's `entitled` for the person's personal workspace, remembered at sign-in (absent: not set). */
    public const PERSONAL = 'infodot.personal_entitled';

    public function __construct(private readonly EntitlementClient $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlements->enabled() || $this->isExempt($request)) {
            return $next($request);
        }

        $user = $request->user() ?? Auth::guard('web')->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $team = $user->currentTeam;
        $team = $team instanceof Team ? $team : null;

        // A personal workspace, no workspace, or a team InfoDot does not know: the read endpoint is never asked. What
        // InfoDot said about the person's personal workspace at sign-in decides: exactly false is a refusal
        // (individuals hold the free core only); true, or nothing remembered, carries on.
        if (! $team || $team->personal_team || blank($team->infodot_team_uuid)) {
            if (! $request->hasSession() || $request->session()->get(self::PERSONAL) !== false) {
                return $next($request);
            }

            return $this->refuse($request, $next, $user, 'company_required', null, $team);
        }

        $answer = $this->entitlements->forCompany((string) $team->infodot_team_uuid);

        if ($answer === null || $answer['entitled'] === true) {
            return $next($request);
        }

        return $this->refuse($request, $next, $user, $answer['status'], $team->name, $team);
    }

    private function isExempt(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        // The operator console: dispatch, safety incidents, refunds and settlement are run from it.
        if (in_array('operator', $route->gatherMiddleware(), true)) {
            return true;
        }

        $name = (string) $route->getName();

        if ($name !== '' && Str::is(self::EXEMPT, $name)) {
            return true;
        }

        // Going offline is always allowed; going online is a new session's action.
        if ($name === 'drivers.toggle-online') {
            $profile = $route->parameter('driverProfile');

            return is_object($profile) && ($profile->is_online ?? false) === true;
        }

        return false;
    }

    private function refuse(Request $request, Closure $next, User $user, string $reason, ?string $company, ?Team $current): Response
    {
        // Layer 2: a trip in progress is never interrupted. Only asked on the refusal path, so a normal request costs nothing.
        if ($this->hasTripInProgress($user)) {
            return $next($request);
        }

        // Livewire throws away any non-redirect response a persistent middleware returns, so a refusal page would let
        // a component update run. On a Livewire update the refusal must abort: nothing runs and no component returns.
        if ($request->headers->has('X-Livewire') || request()->headers->has('X-Livewire')) {
            abort(403);
        }

        if ($request->expectsJson() || $request->is('api/*') || str_contains('/'.$request->path().'/', '/api/')) {
            return EntitlementRefusal::json($reason, $company);
        }

        $others = $user->allTeams()->reject(fn ($other) => $other->id === $current?->id || $other->personal_team || blank($other->infodot_team_uuid));

        return EntitlementRefusal::response($reason, $company, $others);
    }

    private function hasTripInProgress(User $user): bool
    {
        $passenger = array_map(fn (RideStatus $s) => $s->value, array_filter(RideStatus::cases(), fn (RideStatus $s) => $s->isActiveForPassenger()));
        $driver = RideStatus::values(RideStatus::driverHoldingStates());

        return Ride::query()->where(fn ($q) => $q
            ->where(fn ($p) => $p->where('passenger_id', $user->id)->whereIn('status', $passenger))
            ->orWhere(fn ($d) => $d->where('driver_id', $user->id)->whereIn('status', $driver)))->exists();
    }
}
