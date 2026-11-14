<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The plain page a person gets when their company does not hold Dot.Ehail (R-50): at sign-in, when InfoDot says no
 * company of theirs is entitled, and during a session, when the current company's access has ended. Worded from the
 * reason InfoDot gave (a redeem `access` reason or a read-endpoint `status`). It shows nothing from the person's
 * trips, wallet or account, and it never mentions a trip in progress (those are never refused, see
 * EnsureCompanyEntitled).
 */
class EntitlementRefusal
{
    /**
     * @param  iterable<int, object>  $otherCompanies  the person's other companies here, to switch to
     */
    public static function response(string $reason, ?string $company, iterable $otherCompanies = []): Response
    {
        return response()
            ->view('entitlement.refused', [
                ...self::wording($reason, $company),
                'platformsUrl' => self::platformsUrl(),
                'otherCompanies' => collect($otherCompanies)->values(),
            ], 403)
            ->header('Cache-Control', 'no-store, private');
    }

    /** The same refusal for a script that asked for JSON: no page, no data. */
    public static function json(string $reason, ?string $company): JsonResponse
    {
        return response()->json([
            'error' => 'entitlement_required',
            'message' => self::wording($reason, $company)['title'],
            'platforms_url' => self::platformsUrl(),
        ], 403)->header('Cache-Control', 'no-store, private');
    }

    public static function platformsUrl(): string
    {
        return rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/').'/platforms/'.config('ecosystem.infodot.platform', 'ehail');
    }

    /**
     * @return array{stamp: string, title: string, body: string}
     */
    public static function wording(string $reason, ?string $company): array
    {
        $for = filled($company) ? $company : 'your company';
        $kept = 'Your account, trip history and payments are kept as they are. A trip already under way is never interrupted.';

        return match ($reason) {
            'suspended' => [
                'stamp' => 'Suspended',
                'title' => "Dot.Ehail is suspended for {$for}",
                'body' => "Nobody in the company can book or drive until the suspension is lifted. {$kept} Platforms in InfoDot shows what is needed.",
            ],
            'payment_required' => [
                'stamp' => 'Payment needed',
                'title' => "Dot.Ehail needs a payment for {$for}",
                'body' => "Ehail opens again once the payment is settled. {$kept} An owner or administrator can settle it from Platforms in InfoDot.",
            ],
            'subscription_expired', 'trial_ended', 'expired', 'cancelled' => [
                'stamp' => 'Ended',
                'title' => "Dot.Ehail has ended for {$for}",
                'body' => "The company no longer holds this platform. {$kept} An owner or administrator can take it up again from Platforms in InfoDot.",
            ],
            'activation_pending' => [
                'stamp' => 'Not yet on',
                'title' => "Dot.Ehail is waiting to be switched on for {$for}",
                'body' => 'The request is in and has not been completed. Platforms in InfoDot shows where it stands.',
            ],
            'not_permitted_for_you' => [
                'stamp' => 'Not on your list',
                'title' => "{$for} has not given you Dot.Ehail",
                'body' => 'The company holds this platform and chooses who uses it. An owner or administrator can add you from Platforms in InfoDot.',
            ],
            'no_personal_access', 'company_required' => [
                'stamp' => 'Company needed',
                'title' => 'Dot.Ehail needs a company that has switched it on',
                'body' => "A personal workspace does not hold this platform. Add or join a company in InfoDot and switch Dot.Ehail on for it from Platforms. {$kept}",
            ],
            'temporarily_unavailable', 'not_launched' => [
                'stamp' => 'Unavailable',
                'title' => 'Dot.Ehail is not available right now',
                'body' => 'InfoDot is not handing anyone over to this platform at the moment. Platforms in InfoDot shows when it is back.',
            ],
            default => [
                'stamp' => 'Not switched on',
                'title' => "Dot.Ehail is not switched on for {$for}",
                'body' => "An owner or administrator can switch it on from Platforms in InfoDot. {$kept}",
            ],
        };
    }
}
