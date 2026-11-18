<?php

namespace App\Support;

/**
 * Where the "Sign in" and "Create account" entry points of the signed-out pages go. With DOTSSO_ENABLED they go straight
 * to the central sign-in; otherwise they behave exactly as before. The interception in the shared package would catch
 * /login and /register anyway, so this saves the hop and, on the public pages, keeps the page the person was on.
 */
class CentralSignIn
{
    public static function active(): bool
    {
        return (bool) config('dotsso.enabled') && ! (bool) config('dotsso.allow_local_login');
    }

    /** The plain start of the central sign-in (no page to return to), or the local login while the flag is off. */
    public static function start(): string
    {
        return self::active() ? route('dotsso.login') : route('login');
    }

    /** "Sign in": the central sign-in, coming back to the current page when this is a public page (GET) . */
    public static function login(): string
    {
        if (! self::active()) {
            return route('login');
        }

        $request = request();

        return $request->isMethod('GET') && ! $request->is('login', 'register', 'sso/*', 'sign-in')
            ? route('sign-in', ['return' => $request->getRequestUri()])
            : route('dotsso.login');
    }

    /** @param  string  $fallback  where "Create account" goes when the central sign-in is off */
    public static function register(string $fallback): string
    {
        return self::active() ? route('dotsso.login', ['screen' => 'register']) : $fallback;
    }
}
