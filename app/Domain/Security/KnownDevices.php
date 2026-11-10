<?php

namespace App\Domain\Security;

use App\Models\User;
use App\Notifications\SecurityNoticeNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * New-device sign-in notices (R-46 security S-27). A browser carries a random, encrypted, long-lived device cookie;
 * only its keyed hash is stored, with no IP address, user agent or location. A sign-in from a browser this account
 * has not used before emails the holder, except the very first sign-in of an account.
 */
class KnownDevices
{
    public const COOKIE = 'eh_device';

    public function signedIn(User $user, Request $request): void
    {
        if (! config('ehail_security.new_device_notice', true)) {
            return;
        }

        $token = (string) $request->cookie(self::COOKIE, '');
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            $token = Str::random(40);
        }
        Cookie::queue(Cookie::make(self::COOKIE, $token, 60 * 24 * 400, null, null, null, true, false, 'lax'));

        $hash = hash_hmac('sha256', $user->id.'|'.$token, 'ehail.device|'.config('app.key'));
        $known = DB::table('security_known_devices')->where('user_id', $user->id);

        if ((clone $known)->where('device_hash', $hash)->exists()) {
            (clone $known)->where('device_hash', $hash)->update(['last_seen_at' => now()]);

            return;
        }

        $firstEver = ! (clone $known)->exists();

        try {
            DB::table('security_known_devices')->insert(['user_id' => $user->id, 'device_hash' => $hash, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if (! $firstEver) {
            $user->notify(new SecurityNoticeNotification(
                'new_device',
                'New sign-in',
                'Your Dot.Ehail account was signed in from a browser it has not used before, on '.now()->timezone('Africa/Johannesburg')->format('j M Y \a\t H:i').'.',
            ));
        }
    }
}
