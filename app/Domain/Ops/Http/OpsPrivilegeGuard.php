<?php

namespace App\Domain\Ops\Http;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Support\Models\SupportCase;
use App\Domain\Support\Models\SupportMessage;
use App\Models\Ride;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role check for the few routes outside the `operator` middleware where other domains let any platform operator in
 * by the users.is_platform_operator flag alone (geo driver positions, support case pages). Pushed onto the web
 * group by EhailOpsServiceProvider. It only ever narrows: a ride's own passenger or driver, or a case's own
 * requester or counterparty, is never affected; an operator who is not a party needs the named permission.
 */
class OpsPrivilegeGuard
{
    /** @var array<string, string> route name => permission an operator who is not a party needs */
    public const ROUTES = [
        'geo.rides.driver-location' => OpsPermission::DRIVERS_POSITIONS,
        'support.cases.show' => OpsPermission::SUPPORT_VIEW,
        'support.cases.reply' => OpsPermission::SUPPORT_ACT,
        'support.attachments.show' => OpsPermission::SUPPORT_VIEW,
    ];

    public function __construct(private readonly OpsAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $name = $route?->getName();
        $user = $request->user();

        if ($name === null || ! isset(self::ROUTES[$name]) || ! $this->access->isOperator($user)) {
            return $next($request);
        }

        if (! $this->isParty($user->id, $route->parameters()) && ! $this->access->can($user, self::ROUTES[$name])) {
            abort(403, 'Your operations role does not include this.');
        }

        return $next($request);
    }

    /** @param array<string, mixed> $parameters */
    private function isParty(int $userId, array $parameters): bool
    {
        $ride = $parameters['ride'] ?? null;
        if ($ride !== null) {
            $ride = $ride instanceof Ride ? $ride : Ride::find($ride);

            return $ride !== null && ($ride->passenger_id === $userId || $ride->driver_id === $userId);
        }

        $case = $parameters['case'] ?? null;
        $message = $parameters['message'] ?? null;
        if ($message !== null) {
            $message = $message instanceof SupportMessage ? $message : SupportMessage::find($message);
            $case = $message?->support_case_id;
        }

        if ($case !== null) {
            $case = $case instanceof SupportCase ? $case : SupportCase::find($case);

            return $case !== null && ($case->user_id === $userId || $case->counterparty_user_id === $userId);
        }

        return false;
    }
}
