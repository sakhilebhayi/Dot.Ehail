<?php

namespace Tests\Concerns;

use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Models\PushSubscription;
use App\Domain\Comms\Push\Base64Url;
use App\Domain\Comms\Push\EcKeys;
use App\Domain\Comms\Push\PushSubscriptions;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Comms test helpers: real VAPID keys, a browser-side key pair per subscription (so a test can
 * decrypt what the server pushed), and shortcuts over the delivery log.
 */
trait CreatesCommsFixtures
{
    /** @var array<int, array{private: string, public: string, auth: string}> subscription id => browser keys */
    protected array $browserKeys = [];

    protected function configurePush(): void
    {
        $keys = EcKeys::generate();
        config([
            'ehail_comms.push.public_key' => Base64Url::encode($keys['public']),
            'ehail_comms.push.private_key' => Base64Url::encode($keys['private']),
        ]);
    }

    protected function deliveringMail(): void
    {
        config(['mail.default' => 'smtp']);
    }

    protected function subscribe(User $user, string $endpoint = 'https://fcm.googleapis.com/fcm/send/device-'): PushSubscription
    {
        $keys = EcKeys::generate();
        $auth = random_bytes(16);
        $endpoint = str_ends_with($endpoint, '-') ? $endpoint.bin2hex(random_bytes(6)) : $endpoint;

        $subscription = app(PushSubscriptions::class)->subscribe($user, $endpoint, Base64Url::encode($keys['public']), Base64Url::encode($auth),
            'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36');
        $this->browserKeys[$subscription->id] = ['private' => $keys['private'], 'public' => $keys['public'], 'auth' => $auth];

        return $subscription;
    }

    /** @return Collection<int, NotificationDelivery> */
    protected function deliveries(User $user, ?string $type = null): Collection
    {
        return NotificationDelivery::where('user_id', $user->id)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('id')->get();
    }

    protected function delivery(User $user, string $type, string $channel): ?NotificationDelivery
    {
        return NotificationDelivery::where('user_id', $user->id)->where('type', $type)->where('channel', $channel)->first();
    }

    /** @return Collection<int, array<string, mixed>> the in-app records' data */
    protected function inboxData(User $user, ?string $type = null): Collection
    {
        return $user->notifications()->get()->map(fn ($n) => (array) $n->data)
            ->when($type, fn ($c) => $c->where('type', $type))->values();
    }
}
