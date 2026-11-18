<?php

use App\Sso\EhailProvisioner;

/*
 * Dot.Ehail's own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false) and the session guard (the package defaults it to `web`), comes from the package.
 */
return [
    'provisioner' => EhailProvisioner::class,

    // Ehail has always created an account for a person InfoDot vouches for (INFODOT_PROVISION_USERS), so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    'landing' => '/dashboard',

    // A deep link is only ever a same-origin path (the package enforces that). The deny-list names the machine and
    // sign-in doors so a link can never point at them. The public pages (landing, fare calculator) never need a sign-in; a person
    // who chooses to sign in from one of them comes back to it. Signed trip-share and trusted-contact links are public tokens, never a destination.
    'deep_link' => [
        'allow' => ['#^/#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm|webhooks|api|livewire|csp-report|up|t|safety/contact)(/|\?|$)#i'],
    ],

    // Nothing extra is exempt. The fare calculator, trip-share and trusted-contact pages are not sign-in pages, so they are
    // never intercepted; the Paystack webhook, Sanctum, /api, /csp-report and /up are fixed machine paths in the package.
    'allow_paths' => [],
];
