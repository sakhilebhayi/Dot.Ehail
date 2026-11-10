<?php

namespace Tests\Unit\Pricing;

use App\Domain\Pricing\Support\TimeWindows;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class TimeWindowsTest extends TestCase
{
    private const TZ = 'Africa/Johannesburg';

    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, self::TZ)->utc();
    }

    public function test_null_or_empty_means_always(): void
    {
        $this->assertTrue(TimeWindows::contains(null, $this->at('2026-11-10 03:00'), self::TZ));
        $this->assertTrue(TimeWindows::contains([], $this->at('2026-11-10 03:00'), self::TZ));
    }

    public function test_same_day_window_start_inclusive_end_exclusive(): void
    {
        $weekdayMornings = [['days' => [1, 2, 3, 4, 5], 'start' => '06:00', 'end' => '09:00']];

        // 2026-11-10 is a Tuesday.
        $this->assertTrue(TimeWindows::contains($weekdayMornings, $this->at('2026-11-10 06:00'), self::TZ));
        $this->assertTrue(TimeWindows::contains($weekdayMornings, $this->at('2026-11-10 08:59'), self::TZ));
        $this->assertFalse(TimeWindows::contains($weekdayMornings, $this->at('2026-11-10 09:00'), self::TZ));
        $this->assertFalse(TimeWindows::contains($weekdayMornings, $this->at('2026-11-14 07:00'), self::TZ), 'Saturday');
    }

    public function test_window_across_midnight_belongs_to_the_day_it_starts(): void
    {
        $fridayNight = [['days' => [5], 'start' => '22:00', 'end' => '04:00']];

        $this->assertTrue(TimeWindows::contains($fridayNight, $this->at('2026-11-13 22:00'), self::TZ), 'Fri 22:00');
        $this->assertTrue(TimeWindows::contains($fridayNight, $this->at('2026-11-13 23:59'), self::TZ), 'Fri 23:59');
        $this->assertTrue(TimeWindows::contains($fridayNight, $this->at('2026-11-14 00:00'), self::TZ), 'Sat 00:00');
        $this->assertTrue(TimeWindows::contains($fridayNight, $this->at('2026-11-14 03:59'), self::TZ), 'Sat 03:59');
        $this->assertFalse(TimeWindows::contains($fridayNight, $this->at('2026-11-14 04:00'), self::TZ), 'Sat 04:00');
        $this->assertFalse(TimeWindows::contains($fridayNight, $this->at('2026-11-14 22:30'), self::TZ), 'Sat 22:30 is not Friday');
        $this->assertFalse(TimeWindows::contains($fridayNight, $this->at('2026-11-13 01:00'), self::TZ), 'Fri 01:00 belongs to Thursday');
    }

    public function test_sunday_night_wraps_to_monday(): void
    {
        $sundayNight = [['days' => [7], 'start' => '23:00', 'end' => '02:00']];

        $this->assertTrue(TimeWindows::contains($sundayNight, $this->at('2026-11-16 01:30'), self::TZ), 'Mon 01:30');
    }

    public function test_evaluated_in_the_rule_timezone_not_utc(): void
    {
        // 21:30 UTC is 23:30 in Johannesburg (UTC+2, no daylight saving).
        $lateEvening = [['days' => [2], 'start' => '23:00', 'end' => '24:00']];

        $this->assertTrue(TimeWindows::contains($lateEvening, CarbonImmutable::parse('2026-11-10 21:30', 'UTC'), self::TZ));
        $this->assertFalse(TimeWindows::contains($lateEvening, CarbonImmutable::parse('2026-11-10 23:30', 'UTC'), self::TZ));
    }

    public function test_johannesburg_has_no_daylight_saving_shift_across_the_year(): void
    {
        $window = [['start' => '12:00', 'end' => '13:00']];

        foreach (['2026-01-15', '2026-04-15', '2026-07-15', '2026-10-15'] as $date) {
            $this->assertTrue(TimeWindows::contains($window, CarbonImmutable::parse($date.' 10:30', 'UTC'), self::TZ), $date);
        }
    }

    public function test_equal_start_and_end_is_the_whole_day(): void
    {
        $this->assertTrue(TimeWindows::contains([['days' => [2], 'start' => '00:00', 'end' => '00:00']], $this->at('2026-11-10 17:45'), self::TZ));
        $this->assertFalse(TimeWindows::contains([['days' => [3], 'start' => '00:00', 'end' => '00:00']], $this->at('2026-11-10 17:45'), self::TZ));
    }

    public function test_validation(): void
    {
        $this->assertSame([], TimeWindows::validate(null));
        $this->assertSame([], TimeWindows::validate([['days' => [1, 7], 'start' => '00:00', 'end' => '24:00']]));
        $this->assertNotEmpty(TimeWindows::validate([['days' => [8]]]));
        $this->assertNotEmpty(TimeWindows::validate([['start' => '25:00']]));
        $this->assertNotEmpty(TimeWindows::validate([['end' => '24:30']]));
        $this->assertNotEmpty(TimeWindows::validate('weekdays'));
    }
}
