<?php

namespace App\Sso;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Mail\SsoClaimCodeMail;
use App\Models\User;
use Dot\SsoClient\Contracts\UserProvisioner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Mail;

/**
 * What the shared sign-in package needs to know about Ehail's users table. Matching rules and the claim flow live in the
 * package; this class only reads and writes users. It never touches a password, a team membership, two-factor settings
 * or tokens.
 */
class EhailProvisioner implements UserProvisioner
{
    public function findByUuid(string $uuid): ?Authenticatable
    {
        return User::query()->where('infodot_user_uuid', $uuid)->first();
    }

    public function findByEmail(string $email): ?Authenticatable
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
    }

    public function uuidOf(Authenticatable $user): ?string
    {
        return $user instanceof User ? $user->infodot_user_uuid : null;
    }

    public function hasVerifiedEmail(Authenticatable $user): bool
    {
        return $user instanceof User && $user->email_verified_at !== null;
    }

    public function link(Authenticatable $user, string $uuid): void
    {
        $user->forceFill(['infodot_user_uuid' => $uuid])->save();
    }

    public function confirmEmail(Authenticatable $user): void
    {
        if ($user instanceof User && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    public function create(array $claims): ?Authenticatable
    {
        return app(ProvisionUserFromInfoDot::class)->create($claims['email'], $claims['name'], $claims['sub']);
    }

    public function afterSignIn(Authenticatable $user, array $claims): void
    {
        // Nothing to stamp here: the session's `ehail.signed_in_at` (RequireRecentSignIn) and the known-device record are set by the
        // Login event, which the package's session-guard login fires, so a central sign-in satisfies the recent-sign-in gate by itself.
        // Company teams are not in the OIDC token (phase 3/4). The personal workspace is made at creation.
    }

    public function sendClaimCode(Authenticatable $user, string $code, int $minutes): void
    {
        Mail::to($user->email)->send(new SsoClaimCodeMail($user->name, $code, $minutes));
    }
}
