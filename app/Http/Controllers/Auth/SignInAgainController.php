<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Sign in again" for the recent-sign-in gate (RequireRecentSignIn) while the central sign-in is on. A person who signed in through
 * InfoDot has no password of their own to confirm, so the gate's other door is a fresh sign-in: this ends the local session (keeping the
 * page the gate remembered as the intended URL) and starts the central sign-in. The Login event then stamps `ehail.signed_in_at`, which
 * is what the gate checks. The gate itself is unchanged.
 */
class SignInAgainController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if (! config('dotsso.enabled')) {
            return redirect()->route('password.confirm');
        }

        $intended = $request->session()->get('url.intended');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (is_string($intended)) {
            $request->session()->put('url.intended', $intended);
        }

        return redirect()->route('dotsso.login');
    }
}
