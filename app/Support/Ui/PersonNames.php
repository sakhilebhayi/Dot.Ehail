<?php

namespace App\Support\Ui;

use App\Models\User;

/**
 * People by name instead of "user 15" / "driver 9" on operations surfaces (D-1913). Operators may see full names
 * (they already do on every list); a person no longer on record reads as "a deleted account (#id)". Memoised per
 * request so a timeline of fifty events costs one query: the memo belongs to the request it was read in and is
 * dropped when the next request (or a new application instance) asks.
 */
final class PersonNames
{
    /** @var array<int, ?string> */
    private static array $names = [];

    /** @var \WeakReference<object>|null the request the memo was read in */
    private static ?\WeakReference $owner = null;

    /** @param  iterable<int|string|null>  $ids */
    public static function prime(iterable $ids): void
    {
        $request = app()->bound('request') ? app('request') : app();

        if (self::$owner?->get() !== $request) {
            self::$names = [];
            self::$owner = \WeakReference::create($request);
        }

        $missing = [];

        foreach ($ids as $id) {
            if ($id !== null && (int) $id > 0 && ! array_key_exists((int) $id, self::$names)) {
                $missing[] = (int) $id;
            }
        }

        if ($missing === []) {
            return;
        }

        $found = User::query()->whereIn('id', array_unique($missing))->pluck('name', 'id');

        foreach (array_unique($missing) as $id) {
            $name = trim((string) ($found[$id] ?? ''));
            self::$names[$id] = $name === '' ? null : $name;
        }
    }

    /** "Thabo Nkosi", or "a deleted account (#15)". */
    public static function of(int|string|null $id): ?string
    {
        if ($id === null || (int) $id <= 0) {
            return null;
        }

        self::prime([(int) $id]);

        return self::$names[(int) $id] ?? 'a deleted account (#'.(int) $id.')';
    }

    public static function forget(): void
    {
        self::$names = [];
        self::$owner = null;
    }
}
