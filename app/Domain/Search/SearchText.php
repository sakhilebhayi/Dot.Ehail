<?php

namespace App\Domain\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * The text of a header search, made safe once: trimmed, collapsed, cut to 80 characters, and with the LIKE wildcards
 * (% _ \) replaced by spaces so a person cannot turn a search into a pattern (SQLite has no default LIKE escape, so
 * stripping them is the portable fix). Every query below is parameterised.
 */
final class SearchText
{
    public const MIN = 2;

    public const MAX = 80;

    public static function clean(mixed $q): string
    {
        if (! is_string($q)) {
            return '';
        }

        $q = (string) preg_replace('/[%_\\\\\p{C}]+/u', ' ', $q);
        $q = trim((string) preg_replace('/\s+/u', ' ', $q));

        return mb_substr($q, 0, self::MAX);
    }

    /** "#123" or "123" names a ride number. */
    public static function rideNumber(string $q): ?int
    {
        $digits = ltrim($q, '#');

        return $digits !== '' && ctype_digit($digits) && strlen($digits) <= 12 ? (int) $digits : null;
    }

    public static function like(string $q): string
    {
        return '%'.mb_strtolower($q).'%';
    }

    /** where lower(column) like %q% (case-insensitive on SQLite and PostgreSQL alike). */
    public static function whereLike(Builder $query, string $column, string $q, bool $or = true): Builder
    {
        return $query->{$or ? 'orWhereRaw' : 'whereRaw'}('lower('.$column.') like ?', [self::like($q)]);
    }
}
