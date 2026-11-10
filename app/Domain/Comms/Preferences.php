<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * A person's channel choices per category. In-app is always on. Critical categories (safety) are
 * always on for push and email and cannot be changed; SMS for them needs a confirmed number.
 */
final class Preferences
{
    public function __construct(private readonly CommsCategories $categories) {}

    public function allows(User $user, string $category, string $channel): bool
    {
        if ($channel === 'in_app') {
            return true;
        }

        if (! $this->categories->allows($category, $channel)) {
            return false;
        }

        if ($this->categories->isCritical($category)) {
            return true;
        }

        return $this->stored($user)[$category][$channel] ?? $this->categories->default($category, $channel);
    }

    /**
     * True only when the person themselves switched this channel off (a stored choice), never
     * from a default. Used to gate email that other parts of the app already sent before comms
     * existed (Dot.Notify), so their behaviour only changes when someone asks for it.
     */
    public function explicitlyOff(User $user, string $category, string $channel): bool
    {
        if ($this->categories->isCritical($category)) {
            return false;
        }

        return ($this->stored($user)[$category][$channel] ?? null) === false;
    }

    /**
     * Matrix for the screen and the JSON API.
     *
     * @return array<string, array{label: string, description: string, critical: bool, critical_reason: ?string, channels: array<string, array{enabled: bool, locked: bool}>}>
     */
    public function matrix(User $user, ?string $surface = null): array
    {
        $out = [];
        $definitions = $surface === null ? $this->categories->all() : $this->categories->forSurface($surface);

        foreach ($definitions as $key => $definition) {
            $critical = $this->categories->isCritical($key);
            $channels = [];

            foreach ($this->categories->channels($key) as $channel) {
                $channels[$channel] = [
                    'enabled' => $this->allows($user, $key, $channel),
                    'locked' => $critical,
                ];
            }

            $out[$key] = [
                'label' => (string) $definition['label'],
                'description' => (string) ($definition['description'] ?? ''),
                'critical' => $critical,
                'critical_reason' => $critical ? (string) ($definition['critical_reason'] ?? '') : null,
                'channels' => $channels,
            ];
        }

        return $out;
    }

    /**
     * @throws PreferenceLocked when the category is critical
     * @throws InvalidArgumentException for an unknown category or a channel it does not use
     */
    public function set(User $user, string $category, string $channel, bool $enabled): void
    {
        if (! $this->categories->exists($category) || ! $this->categories->allows($category, $channel)) {
            throw new InvalidArgumentException("Unknown choice {$category}/{$channel}.");
        }

        if ($this->categories->isCritical($category)) {
            throw new PreferenceLocked($category);
        }

        NotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'category' => $category, 'channel' => $channel],
            ['enabled' => $enabled],
        );

        Cache::forget($this->cacheKey($user));
    }

    /** @return array<string, array<string, bool>> */
    private function stored(User $user): array
    {
        return Cache::remember($this->cacheKey($user), 300, function () use ($user) {
            $rows = [];
            foreach (NotificationPreference::where('user_id', $user->id)->get(['category', 'channel', 'enabled']) as $row) {
                $rows[$row->category][$row->channel] = (bool) $row->enabled;
            }

            return $rows;
        });
    }

    private function cacheKey(User $user): string
    {
        return 'comms.prefs.'.$user->id;
    }
}
