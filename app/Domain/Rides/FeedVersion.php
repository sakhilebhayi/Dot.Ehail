<?php

namespace App\Domain\Rides;

/**
 * Feed versions are epoch MICROseconds (about 1.8e15 today, inside the
 * 2^53 range a JavaScript number holds exactly), strictly increasing per
 * row. Microseconds make two writes in the same instant practically
 * impossible, so a poller holding version V never misses a later change
 * that happened to share V's timestamp. Database timestamp columns keep
 * second precision, which is why versions are separate integer columns.
 */
final class FeedVersion
{
    public static function now(): int
    {
        return (int) now()->getPreciseTimestamp(6);
    }

    public static function next(int $previous = 0): int
    {
        return max(self::now(), $previous + 1);
    }
}
