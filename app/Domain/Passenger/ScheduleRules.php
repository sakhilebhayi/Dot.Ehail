<?php

namespace App\Domain\Passenger;

/**
 * The scheduled-ride rules the passenger is shown, read from dispatch's configuration so the words on screen are
 * the rules dispatch enforces (docs/R46_DISPATCH.md section 7).
 */
final class ScheduleRules
{
    /**
     * @return array{min_lead_minutes: int, max_lead_days: int, allocation_lead_minutes: int, free_change_until_minutes: int, grace_minutes: int, price_tolerance_percent: int}
     */
    public static function toArray(): array
    {
        return [
            'min_lead_minutes' => (int) config('ehail_dispatch.scheduled.min_lead_minutes', 30),
            'max_lead_days' => (int) config('ehail_dispatch.scheduled.max_lead_days', 30),
            'allocation_lead_minutes' => (int) config('ehail_dispatch.scheduled.allocation_lead_minutes', 15),
            'free_change_until_minutes' => (int) config('ehail_dispatch.scheduled.free_change_until_minutes', 60),
            'grace_minutes' => (int) config('ehail_dispatch.scheduled.grace_minutes', 10),
            'price_tolerance_percent' => (int) config('ehail_dispatch.scheduled.price_guarantee_percent', 10),
        ];
    }
}
