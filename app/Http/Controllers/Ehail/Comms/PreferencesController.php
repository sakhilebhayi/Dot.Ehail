<?php

namespace App\Http\Controllers\Ehail\Comms;

use App\Domain\Comms\ChannelStatus;
use App\Domain\Comms\CommsCategories;
use App\Domain\Comms\Models\SmsNumber;
use App\Domain\Comms\PreferenceLocked;
use App\Domain\Comms\Preferences;
use App\Domain\Comms\Push\PushSubscriptions;
use App\Domain\Comms\Push\VapidKeys;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** JSON preferences: categories x channels, plus each channel's honest status on this server. */
class PreferencesController extends Controller
{
    public function __construct(
        private readonly Preferences $preferences,
        private readonly ChannelStatus $status,
        private readonly VapidKeys $vapid,
        private readonly PushSubscriptions $subscriptions,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $request->validate(['surface' => ['nullable', Rule::in(array_keys(CommsCategories::SURFACES))]]);

        return response()->json(self::state($request->user(), $request->input('surface'), $this->preferences, $this->status, $this->vapid, $this->subscriptions))
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'choices' => ['required', 'array', 'min:1', 'max:60'],
            'choices.*.category' => ['required', 'string', 'max:40'],
            'choices.*.channel' => ['required', Rule::in(CommsCategories::EXTRA_CHANNELS)],
            'choices.*.enabled' => ['required', 'boolean'],
        ]);

        foreach ($data['choices'] as $choice) {
            try {
                $this->preferences->set($request->user(), $choice['category'], $choice['channel'], (bool) $choice['enabled']);
            } catch (PreferenceLocked $e) {
                return response()->json(['error' => 'locked', 'message' => $e->getMessage(), 'category' => $e->category], 422);
            } catch (InvalidArgumentException) {
                return response()->json(['error' => 'unknown_choice', 'message' => 'That category or channel does not exist.'], 422);
            }
        }

        return response()->json(self::state($request->user(), $request->input('surface'), $this->preferences, $this->status, $this->vapid, $this->subscriptions));
    }

    /** @return array<string, mixed> */
    public static function state(User $user, ?string $surface, Preferences $preferences, ChannelStatus $status, VapidKeys $vapid, PushSubscriptions $subscriptions): array
    {
        $sms = SmsNumber::where('user_id', $user->id)->first();

        return [
            'categories' => $preferences->matrix($user, $surface),
            'channels' => $status->summary(),
            'push' => [
                'configured' => $vapid->configured(),
                'public_key' => $vapid->publicKeyBase64(),
                'devices' => $subscriptions->active($user)->map(fn ($s) => [
                    'id' => $s->id,
                    'label' => $s->device_label ?? 'A browser',
                    'since' => $s->created_at?->toIso8601String(),
                    'last_success_at' => $s->last_success_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'sms' => [
                'configured' => $status->smsConfigured(),
                'number' => $sms?->maskedNumber(),
                'verified' => $sms?->verified_at !== null,
                'awaiting_code' => $sms !== null && $sms->verified_at === null && $sms->code_expires_at?->isFuture() === true,
            ],
        ];
    }
}
