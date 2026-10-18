<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InfoDot\IdentityClient;
use App\Services\InfoDot\InfoDotUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class EcosystemAuthController extends Controller
{
    public function handle(Request $request, IdentityClient $infoDot, ProvisionUserFromInfoDot $provision, SyncTeamsFromInfoDot $syncTeams): RedirectResponse
    {
        $token = $request->query('token');

        // A missing, empty or non-string (e.g. token[]=x) value is a plain
        // 403, never a 500.
        abort_unless(is_string($token) && $token !== '', 403);

        $user = $this->userFromLocalToken($token);

        // Production gives each Dot app its own database, so InfoDot's token is
        // not in ours: ask InfoDot to prove it.
        if (! $user && $infoDot->enabled()) {
            try {
                $identity = $infoDot->redeem($token);
            } catch (InfoDotUnavailable) {
                abort(503, 'Sign-in through InfoDot is unavailable right now. Please try again in a moment.');
            }

            $user = $identity ? $provision($identity) : null;

            if ($user && $identity) {
                $syncTeams($user, $identity);
            }
        }

        abort_if(! $user, 403);

        Auth::login($user);
        $request->session()->regenerate();

        return $this->destination($request);
    }

    /** A token minted in a database we share (the original design): found, checked, spent. */
    private function userFromLocalToken(string $token): ?User
    {
        $accessToken = PersonalAccessToken::findToken($token);

        if (
            ! $accessToken
            || ! $accessToken->can('ecosystem:read')
            || ($accessToken->expires_at && $accessToken->expires_at->isPast())
        ) {
            return null;
        }

        $user = $accessToken->tokenable;

        // Single use, even when the owner no longer exists.
        $accessToken->delete();

        return $user instanceof User ? $user : null;
    }

    /**
     * A deep link from InfoDot may name a page to open. Only a path inside this app is
     * honoured (one leading slash, never "//", a backslash, "..", or a control character,
     * raw or percent-encoded), so this can never be turned into an open redirect.
     */
    private function destination(Request $request): RedirectResponse
    {
        $redirect = $request->query('redirect');

        if (is_string($redirect) && $this->isSafeAppPath($redirect)) {
            return redirect($redirect);
        }

        return redirect()->route('dashboard');
    }

    private function isSafeAppPath(string $path): bool
    {
        if (strlen($path) > 2048 || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }

        foreach ([$path, rawurldecode($path)] as $candidate) {
            if (str_contains($candidate, '..') || str_contains($candidate, '\\') || str_starts_with($candidate, '//') || preg_match('/[\x00-\x1f\x7f]/', $candidate)) {
                return false;
            }
        }

        return true;
    }
}
