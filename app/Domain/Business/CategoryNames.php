<?php

namespace App\Domain\Business;

use App\Domain\Catalog\Models\RideCategory;

/**
 * Ride category keys as people read them ("standard" is shown as its catalogue name, "Comfort"). Every surface
 * (passenger, driver offer, ops, business) uses this so a category has one name everywhere (R-46 a11y, D-1710).
 * Memoised once per request (once() is flushed between tests, unlike a static).
 */
final class CategoryNames
{
    public static function for(?string $key): string
    {
        if ($key === null || $key === '') {
            return '';
        }

        $names = once(fn (): array => RideCategory::query()->pluck('name', 'key')->all());

        return $names[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
}
