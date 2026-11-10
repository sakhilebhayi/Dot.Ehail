<?php

namespace App\Domain\Comms;

/**
 * Reads the category table in config/ehail_comms.php: which category a notification type belongs
 * to, which extra channels it may use, which are critical (cannot be switched off) and the defaults.
 */
final class CommsCategories
{
    public const EXTRA_CHANNELS = ['push', 'email', 'sms'];

    /** Categories shown on each surface's preferences page (a person's choices are the same everywhere). */
    public const SURFACES = [
        'passenger' => ['trip', 'scheduled', 'payments', 'safety', 'support', 'account', 'general'],
        'driver' => ['offers', 'trip', 'driver_account', 'earnings', 'safety', 'support', 'account', 'general'],
        'ops' => ['safety', 'fleet_admin', 'support', 'general'],
    ];

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return (array) config('ehail_comms.categories', []);
    }

    /** @return array<string, array<string, mixed>> */
    public function forSurface(string $surface): array
    {
        $all = $this->all();
        $keys = self::SURFACES[$surface] ?? array_keys($all);

        return array_intersect_key($all, array_flip($keys));
    }

    public function exists(string $category): bool
    {
        return isset($this->all()[$category]);
    }

    public function categoryFor(string $type): string
    {
        $category = (string) (config('ehail_comms.types.'.$type) ?? 'general');

        return $this->exists($category) ? $category : 'general';
    }

    public function isCritical(string $category): bool
    {
        return (bool) ($this->all()[$category]['critical'] ?? false);
    }

    /** @return list<string> */
    public function channels(string $category): array
    {
        return array_values(array_intersect(self::EXTRA_CHANNELS, (array) ($this->all()[$category]['channels'] ?? [])));
    }

    public function allows(string $category, string $channel): bool
    {
        return in_array($channel, $this->channels($category), true);
    }

    public function default(string $category, string $channel): bool
    {
        return (bool) ($this->all()[$category]['defaults'][$channel] ?? false);
    }

    public function label(string $category): string
    {
        return (string) ($this->all()[$category]['label'] ?? ucfirst(str_replace('_', ' ', $category)));
    }

    public function isInAppOnlyType(string $type): bool
    {
        return in_array($type, (array) config('ehail_comms.in_app_only_types', []), true);
    }
}
