<?php

namespace App\Domain\Payments\Http;

use App\Domain\Payments\PaymentDueGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware "ehail.payment-clear": a passenger who owes money for an earlier ride cannot request a
 * new one until it is settled. JSON 402 with the rides owed, or a redirect back with the reason.
 */
class EnsureNoPaymentDue
{
    public function __construct(private PaymentDueGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $status = $this->guard->statusFor($user);

        if (! $status['blocked']) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $status['reason'], 'payment_due' => $status['rides']], 402);
        }

        return back()->withErrors(['payment' => $status['reason']]);
    }
}
