<?php

namespace App\Http\Controllers\Ehail\Comms;

use App\Domain\Comms\Models\PushSubscription;
use App\Domain\Comms\Push\PushResult;
use App\Domain\Comms\Push\PushSubscriptions;
use App\Domain\Comms\Push\VapidKeys;
use App\Domain\Comms\Push\WebPushClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Web Push: the public key, subscribe / unsubscribe this browser, and a test message to yourself. */
class PushController extends Controller
{
    public function __construct(private readonly PushSubscriptions $subscriptions, private readonly VapidKeys $vapid) {}

    public function key(): JsonResponse
    {
        return response()->json(['configured' => $this->vapid->configured(), 'public_key' => $this->vapid->publicKeyBase64()]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->vapid->configured()) {
            return response()->json(['error' => 'push_not_configured', 'message' => 'Push notifications are not set up on this service yet.'], 503);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:64'],
        ]);

        try {
            $subscription = $this->subscriptions->subscribe($request->user(), $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $request->userAgent());
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'subscription_refused', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['subscribed' => true, 'device' => $subscription->device_label], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        return response()->json(['subscribed' => false, 'removed' => $this->subscriptions->unsubscribe($request->user(), $data['endpoint'])]);
    }

    /** Is this browser's subscription one we hold for this person? (endpoint in the body, never the URL) */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        return response()->json(['subscribed' => $this->subscriptions->isSubscribed($request->user(), $data['endpoint'])]);
    }

    /** Sends a test message to the person's own devices now and reports what each push service said. */
    public function test(Request $request, WebPushClient $client): JsonResponse
    {
        if (! $this->vapid->configured()) {
            return response()->json(['error' => 'push_not_configured', 'message' => 'Push notifications are not set up on this service yet.'], 503);
        }

        $devices = $this->subscriptions->active($request->user());

        if ($devices->isEmpty()) {
            return response()->json(['error' => 'no_devices', 'message' => 'Turn push on for this device first.'], 422);
        }

        $results = $devices->map(function (PushSubscription $subscription) use ($client) {
            $result = $client->send($subscription, [
                'title' => 'Test notification',
                'body' => 'Push works on this device. Dot.Ehail will use it for the updates you chose.',
                'url' => '/home',
                'tag' => 'push-test',
            ], 'normal', 'push-test');

            if ($result->status === PushResult::GONE) {
                $this->subscriptions->revoke($subscription, (string) $result->reason);
            }

            return ['device' => $subscription->device_label ?? 'A browser', 'accepted' => $result->status === PushResult::SENT, 'reason' => $result->reason];
        })->values()->all();

        $accepted = count(array_filter($results, fn ($r) => $r['accepted']));

        return response()->json([
            'accepted' => $accepted,
            'devices' => $results,
            'message' => $accepted > 0
                ? "Accepted by the push service for {$accepted} device(s). It should appear within seconds."
                : 'No push service accepted the test. Turn push off and on again on this device.',
        ]);
    }
}
