<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for platform-operator-only routes (fleet vetting). Same shape as
 * Dot.Tutor's `operator` middleware.
 */
class EnsurePlatformOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isPlatformOperator(), 403);

        return $next($request);
    }
}
