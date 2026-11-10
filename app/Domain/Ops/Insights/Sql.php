<?php

namespace App\Domain\Ops\Insights;

use Illuminate\Support\Facades\DB;

/**
 * Engine-specific SQL fragments for the insights queries (SQLite in tests, PostgreSQL in production, MySQL
 * tolerated). Only the day bucket and the seconds between two timestamps differ; everything else is plain SQL.
 */
final class Sql
{
    /** 'YYYY-MM-DD' of a timestamp column, in the database's stored time (UTC). */
    public static function day(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM-DD')",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            default => "strftime('%Y-%m-%d', {$column})",
        };
    }

    /** 'YYYY-MM' of a timestamp column. */
    public static function month(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            default => "strftime('%Y-%m', {$column})",
        };
    }

    /** Seconds from $start to $end (both timestamp columns), as a number. */
    public static function seconds(string $start, string $end): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "EXTRACT(EPOCH FROM ({$end} - {$start}))",
            'mysql', 'mariadb' => "TIMESTAMPDIFF(SECOND, {$start}, {$end})",
            default => "((julianday({$end}) - julianday({$start})) * 86400.0)",
        };
    }
}
