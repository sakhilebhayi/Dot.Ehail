<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Dot\SsoClient\Support\DeepLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Sign in" from a public page (the fare calculator, the landing page). Those pages are public, so nothing sends a guest to a login on its own; when a person
 * chooses to sign in, the page they were on is kept as the platform's own server-side intended URL (the package's deep
 * link) after the same-origin and deny-list checks, then the central sign-in starts. The value is never taken from the
 * sign-in request or the callback.
 */
class SignInEntryController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if (! config('dotsso.enabled')) {
            return redirect()->route('login');
        }

        $back = DeepLink::clean($request->query('return'), $request);

        if ($back !== null) {
            $request->session()->put('url.intended', $back);
        }

        return redirect()->route('dotsso.login');
    }
}
