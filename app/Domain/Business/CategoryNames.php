<?php

namespace App\Domain\Business;

use App\Domain\Catalog\Models\RideCategory;

/** Ride category keys as people read them ("standard" is shown as its catalogue name, "Comfort"). */
final class CategoryNames
{
    /** @var array<string, string>|null */
    private static ?array $names = null;

    public static function for(?string $key): string
    {
        if ($key === null || $key === '') {
            return '';
        }

        self::$names ??= RideCategory::query()->pluck('name', 'key')->all();

        return self::$names[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
}
