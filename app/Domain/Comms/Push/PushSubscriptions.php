<?php

namespace App\Domain\Comms\Push;

use App\Domain\Comms\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Subscription lifecycle: a browser subscribes (or re-subscribes after its keys rotate), the
 * person turns push off on that device, or the push service says the subscription is gone. One row
 * per endpoint: a browser that signs in to another account moves to that account.
 */
final class PushSubscriptions
{
    /**
     * @throws InvalidArgumentException with a plain reason when the subscription is unusable
     */
    public function subscribe(User $user, string $endpoint, string $p256dh, string $auth, ?string $userAgent = null): PushSubscription
    {
        if (strlen($endpoint) > 2000 || ! WebPushClient::endpointAllowed($endpoint)) {
            throw new InvalidArgumentException('This browser uses a push service we do not send to.');
        }

        $point = Base64Url::decode($p256dh);
        $secret = Base64Url::decode($auth);

        try {
            EcKeys::publicKey($point);
        } catch (Throwable) {
            throw new InvalidArgumentException('The browser sent an unusable encryption key.');
        }

        if (strlen($secret) !== 16) {
            throw new InvalidArgumentException('The browser sent an unusable authentication secret.');
        }

        $hash = PushSubscription::hashEndpoint($endpoint);
        $subscription = PushSubscription::firstOrNew(['endpoint_hash' => $hash]);
        $subscription->fill([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'p256dh' => $p256dh,
            'auth_secret' => $auth,
            'device_label' => self::deviceLabel($userAgent),
            'revoked_at' => null,
            'revoke_reason' => null,
        ]);
        $subscription->failure_count = 0;
        $subscription->save();

        $this->trim($user);

        return $subscription;
    }

    public function unsubscribe(User $user, string $endpoint): bool
    {
        return PushSubscription::where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->where('user_id', $user->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoke_reason' => 'turned_off']) > 0;
    }

    public function revoke(PushSubscription $subscription, string $reason): void
    {
        $subscription->forceFill(['revoked_at' => now(), 'revoke_reason' => mb_substr($reason, 0, 40)])->save();
    }

    /** @return Collection<int, PushSubscription> */
    public function active(User $user): Collection
    {
        return PushSubscription::active()->where('user_id', $user->id)->orderByDesc('updated_at')->get();
    }

    public function isSubscribed(User $user, string $endpoint): bool
    {
        return PushSubscription::active()->where('user_id', $user->id)->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->exists();
    }

    /** "Chrome on Android": enough to recognise a device in a list, nothing more. */
    public static function deviceLabel(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $platform = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $platform ? "{$browser} on {$platform}" : $browser;
    }

    private function trim(User $user): void
    {
        $max = (int) config('ehail_comms.push.max_subscriptions_per_user', 10);
        $extra = PushSubscription::active()->where('user_id', $user->id)->orderByDesc('updated_at')->skip($max)->take(100)->pluck('id');

        if ($extra->isNotEmpty()) {
            PushSubscription::whereIn('id', $extra)->update(['revoked_at' => now(), 'revoke_reason' => 'too_many_devices']);
        }
    }
}
