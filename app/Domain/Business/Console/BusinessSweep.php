<?php

namespace App\Domain\Business\Console;

use App\Domain\Business\Approvals;
use App\Domain\Business\BusinessBookings;
use App\Domain\Business\Models\BusinessTrip;
use App\Enums\RideStatus;
use App\Models\Ride;
use Illuminate\Console\Command;

/**
 * Every minute: expire approval requests past their decision time; release the limits held by trips
 * whose ride ended without a charge (after the configured delay, so a late fare charge still lands);
 * wipe guest and rider phone numbers once the trip is settled and the retention period has passed; drop prepared checks older than a day.
 */
class BusinessSweep extends Command
{
    protected $signature = 'business:sweep';

    protected $description = 'Expire business approvals, release unbilled reservations, purge rider phones';

    public function handle(Approvals $approvals, BusinessBookings $bookings): int
    {
        $expired = $approvals->expireDue();

        $released = 0;
        $cutoff = now()->subHours(max(1, (int) config('ehail_business.release_unbilled_after_hours', 24)));
        $terminal = array_map(fn (RideStatus $s) => $s->value, array_filter(RideStatus::cases(), fn (RideStatus $s) => $s->isTerminal()));

        BusinessTrip::where('status', BusinessTrip::BOOKED)->whereNotNull('ride_id')->where('updated_at', '<', $cutoff)
            ->chunkById(200, function ($trips) use ($bookings, $terminal, &$released) {
                $ended = Ride::whereIn('id', $trips->pluck('ride_id'))->whereIn('status', $terminal)->pluck('id')->all();

                foreach ($ended as $rideId) {
                    $bookings->rideEnded((int) $rideId);
                    $released++;
                }
            });

        $purged = BusinessTrip::whereNotNull('rider_phone')->whereIn('status', [BusinessTrip::CHARGED, BusinessTrip::RELEASED])->where('updated_at', '<', now()->subDays(max(1, (int) config('ehail_business.guest_phone_retention_days', 30))))
            ->update(['rider_phone' => null, 'rider_phone_purged_at' => now()]);

        $dropped = BusinessTrip::where('status', BusinessTrip::PREPARED)->where('updated_at', '<', now()->subDay())->delete();

        $this->info("Expired {$expired} approval(s), released {$released} trip(s), purged {$purged} phone number(s), dropped {$dropped} unused check(s).");

        return self::SUCCESS;
    }
}
