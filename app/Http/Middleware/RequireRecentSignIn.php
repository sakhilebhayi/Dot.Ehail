<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive actions (a payout bank account, a trusted contact) need proof that the person at the keyboard is the
 * account holder: a sign-in or a password confirmation within `ehail_security.reauth_minutes`. Accounts signed in
 * through InfoDot prove it by signing in again; accounts with a password may confirm it instead.
 * Alias: `ehail.recent-auth` (EhailSecurityServiceProvider). R-46 security S-15.
 */
class RequireRecentSignIn
{
    public const SIGNED_IN_AT = 'ehail.signed_in_at';

    public function handle(Request $request, Closure $next): Response
    {
        $window = max(1, (int) config('ehail_security.reauth_minutes', 15)) * 60;
        $session = $request->hasSession() ? $request->session() : null;
        $latest = max((int) $session?->get('auth.password_confirmed_at', 0), (int) $session?->get(self::SIGNED_IN_AT, 0));

        if ($latest > 0 && time() - $latest <= $window) {
            return $next($request);
        }

        $message = 'For your security, confirm it is you before changing this: sign in again, or confirm your password.';

        if ($request->expectsJson()) {
            return response()->json(['error' => 'reauthentication_required', 'message' => $message, 'confirm_url' => route('password.confirm')], 423);
        }

        $session?->put('url.intended', $request->isMethod('GET') ? $request->fullUrl() : url()->previous());

        return redirect()->route('password.confirm')->with('status', $message);
    }
}
