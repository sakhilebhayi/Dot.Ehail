<?php

namespace App\Http\Middleware;

use App\Domain\Ops\Access\OpsAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for platform-operator-only routes. Two checks: the person is a platform operator (users.is_platform_operator),
 * and one of their operations roles carries the permission this route needs (App\Domain\Ops\Access\RoutePermissions,
 * R-46 ops). An operator with no roles keeps full access (decision D-1001).
 */
class EnsurePlatformOperator
{
    public function __construct(private readonly OpsAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->isPlatformOperator(), 403);
        abort_unless($this->access->allowsRoute($user, $request->route()?->getName()), 403, 'Your operations role does not include this.');

        return $next($request);
    }
}
