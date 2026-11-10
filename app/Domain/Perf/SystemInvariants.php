<?php

namespace App\Domain\Perf;

use App\Models\RidePayment;
use Illuminate\Support\Facades\DB;

/**
 * The correctness invariants the load and failure scenarios assert after every run (architecture section 8,
 * docs/R46_PERF.md section 3). Read-only SQL over the whole database, so it also works as an operator audit.
 *
 * Each check returns the offending ids (at most 20), never personal data.
 */
class SystemInvariants
{
    /** Statuses in which a ride holds its driver. */
    public const HOLDING = ['driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress'];

    /** Statuses in which a ride is live for its passenger. */
    public const LIVE = ['requested', 'searching', 'driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress'];

    /**
     * @return array{ok: bool, breaches: array<string, list<int|string>>, counts: array<string, int>}
     */
    public function check(): array
    {
        $holding = self::HOLDING;
        $breaches = [];

        // 1. A driver never holds two active rides.
        $breaches['driver_with_two_active_rides'] = DB::table('rides')->whereIn('status', $holding)->whereNotNull('driver_id')
            ->groupBy('driver_id')->havingRaw('count(*) > 1')->limit(20)->pluck('driver_id')->all();

        // 2. A ride never has two drivers: one accepted offer per assignment, the guard column agrees with the driver,
        //    and dispatch's availability row points back at the ride.
        $breaches['ride_with_two_accepted_offers'] = DB::table('ride_offers')->join('rides', 'rides.id', '=', 'ride_offers.ride_id')
            ->where('ride_offers.status', 'accepted')
            ->groupBy('ride_offers.ride_id', 'rides.reassignment_count')
            ->havingRaw('count(*) > rides.reassignment_count + 1')->limit(20)->pluck('ride_offers.ride_id')->all();

        $breaches['holding_ride_guard_mismatch'] = DB::table('rides')->whereIn('status', $holding)
            ->where(fn ($q) => $q->whereNull('active_driver_id')->orWhereColumn('active_driver_id', '!=', 'driver_id'))
            ->limit(20)->pluck('id')->all();

        $breaches['availability_points_elsewhere'] = DB::table('driver_availability')->join('rides', 'rides.id', '=', 'driver_availability.current_ride_id')
            ->where(fn ($q) => $q->whereColumn('rides.driver_id', '!=', 'driver_availability.user_id')->orWhereNotIn('rides.status', $holding))
            ->limit(20)->pluck('driver_availability.user_id')->all();

        $breaches['holding_ride_without_availability'] = DB::table('rides')->whereIn('rides.status', $holding)
            ->leftJoin('driver_availability', 'driver_availability.current_ride_id', '=', 'rides.id')
            ->whereNull('driver_availability.id')->limit(20)->pluck('rides.id')->all();

        // 3. One live ride per passenger.
        $breaches['passenger_with_two_live_rides'] = DB::table('rides')->whereIn('status', self::LIVE)
            ->groupBy('passenger_id')->havingRaw('count(*) > 1')->limit(20)->pluck('passenger_id')->all();

        // 4. One offer in flight per ride and per driver.
        $breaches['ride_with_two_pending_offers'] = DB::table('ride_offers')->where('status', 'pending')
            ->groupBy('ride_id')->havingRaw('count(*) > 1')->limit(20)->pluck('ride_id')->all();
        $breaches['driver_with_two_pending_offers'] = DB::table('ride_offers')->where('status', 'pending')
            ->groupBy('driver_id')->havingRaw('count(*) > 1')->limit(20)->pluck('driver_id')->all();

        // 5. No double charge: at most one paid fare payment per ride (refunds are separate rows).
        $breaches['ride_charged_twice'] = DB::table('ride_payments')->where('status', RidePayment::STATUS_PAID)
            ->whereNotNull('ride_id')->where('purpose', 'ride_fare')
            ->groupBy('ride_id')->havingRaw('count(*) > 1')->limit(20)->pluck('ride_id')->all();

        // 6. The ledger balances: every journal, and therefore the whole book, has debits = credits per currency.
        $breaches['unbalanced_journals'] = DB::table('ledger_entries')
            ->groupBy('journal_id', 'currency')->havingRaw('sum(debit_minor) != sum(credit_minor)')->limit(20)->pluck('journal_id')->all();

        // 7. No lost trip events: every ride that left "requested" has a timeline whose last state is the ride's state,
        //    and every completed ride has a started and a completed event.
        $last = DB::table('ride_events')->whereNotNull('to_status')
            ->selectRaw('ride_id, max(sequence) as seq')->groupBy('ride_id');
        $breaches['timeline_disagrees_with_ride'] = DB::table('rides')
            ->joinSub($last, 'last', 'last.ride_id', '=', 'rides.id')
            ->join('ride_events', fn ($j) => $j->on('ride_events.ride_id', '=', 'rides.id')->on('ride_events.sequence', '=', 'last.seq'))
            ->whereColumn('ride_events.to_status', '!=', 'rides.status')
            ->limit(20)->pluck('rides.id')->all();
        $breaches['moved_ride_without_timeline'] = DB::table('rides')->where('status', '!=', 'requested')->whereNotNull('fare_quote_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ride_events')->whereColumn('ride_events.ride_id', 'rides.id'))
            ->limit(20)->pluck('id')->all();
        $breaches['completed_without_trip_events'] = DB::table('rides')->where('status', 'completed')->whereNotNull('fare_quote_id')
            ->where(fn ($q) => $q
                ->whereNotExists(fn ($e) => $e->selectRaw('1')->from('ride_events')->whereColumn('ride_events.ride_id', 'rides.id')->where('to_status', 'in_progress'))
                ->orWhereNotExists(fn ($e) => $e->selectRaw('1')->from('ride_events')->whereColumn('ride_events.ride_id', 'rides.id')->where('to_status', 'completed')))
            ->limit(20)->pluck('id')->all();

        $breaches = array_filter($breaches, fn (array $ids) => $ids !== []);

        return [
            'ok' => $breaches === [],
            'breaches' => $breaches,
            'counts' => [
                'rides' => DB::table('rides')->count(),
                'holding' => DB::table('rides')->whereIn('status', $holding)->count(),
                'completed' => DB::table('rides')->where('status', 'completed')->count(),
                'offers' => DB::table('ride_offers')->count(),
                'accepted_offers' => DB::table('ride_offers')->where('status', 'accepted')->count(),
                'ride_events' => DB::table('ride_events')->count(),
                'paid_payments' => DB::table('ride_payments')->where('status', RidePayment::STATUS_PAID)->count(),
                'journals' => DB::table('ledger_journals')->count(),
            ],
        ];
    }
}
