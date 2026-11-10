<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * State machine v2 (docs/R46_ARCHITECTURE.md section 2) renames four stored
     * values. Every row is kept; only the status string changes:
     *   accepted  -> driver_assigned
     *   en_route  -> driver_arriving
     *   arrived   -> driver_arrived
     *   cancelled -> cancelled_by_passenger when the passenger cancelled it,
     *                otherwise cancelled_by_operator (before v2 only the
     *                passenger or an operator could reach 'cancelled'; a
     *                driver backing out re-queued the ride instead)
     *
     * Each renamed ride gets one ride_events row (type status_migrated) so its
     * timeline says why the value changed.
     *
     * Then the concurrency guard columns are filled for rides that are live
     * right now. Before v2 nothing stopped a driver from holding two accepted
     * rides; if that happened, only the most recent ride holds the guard and
     * the older one is listed in ride_events (reason duplicate_active_driver)
     * so an operator can resolve it. Its status is not touched.
     */
    public function up(): void
    {
        $now = now();
        $renames = [
            'accepted' => 'driver_assigned',
            'en_route' => 'driver_arriving',
            'arrived' => 'driver_arrived',
        ];

        foreach ($renames as $old => $new) {
            $this->renameWhere(fn ($q) => $q->where('status', $old), $old, $new, $now);
        }

        $this->renameWhere(
            fn ($q) => $q->where('status', 'cancelled')->whereColumn('cancelled_by', 'passenger_id'),
            'cancelled', 'cancelled_by_passenger', $now,
        );
        $this->renameWhere(fn ($q) => $q->where('status', 'cancelled'), 'cancelled', 'cancelled_by_operator', $now);

        $holding = ['driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress'];
        $passengerActive = ['requested', 'searching', 'driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress'];

        $this->fillGuard('driver_id', 'active_driver_id', $holding, 'duplicate_active_driver', $now);
        $this->fillGuard('passenger_id', 'active_passenger_id', $passengerActive, 'duplicate_active_passenger', $now);
    }

    public function down(): void
    {
        // Lossy by nature: v2-only states fold back into the nearest v1 value.
        $back = [
            'driver_assigned' => 'accepted',
            'driver_arriving' => 'en_route',
            'driver_arrived' => 'arrived',
            'cancelled_by_passenger' => 'cancelled',
            'cancelled_by_driver' => 'cancelled',
            'cancelled_by_operator' => 'cancelled',
            'no_show' => 'cancelled',
            'failed' => 'cancelled',
            'searching' => 'requested',
            'scheduled' => 'requested',
        ];

        foreach ($back as $new => $old) {
            DB::table('rides')->where('status', $new)->update(['status' => $old]);
        }

        DB::table('rides')->update(['active_driver_id' => null, 'active_passenger_id' => null]);
    }

    private function renameWhere(Closure $scope, string $old, string $new, $now): void
    {
        $scope(DB::table('rides'))->select('id')->chunkById(500, function ($rows) use ($old, $new, $now) {
            $ids = $rows->pluck('id')->all();

            DB::table('rides')->whereIn('id', $ids)->where('status', $old)->update(['status' => $new]);

            $nextSequence = DB::table('ride_events')->whereIn('ride_id', $ids)
                ->groupBy('ride_id')->selectRaw('ride_id, max(sequence) as seq')->pluck('seq', 'ride_id');

            DB::table('ride_events')->insert(array_map(fn ($id) => [
                'ride_id' => $id,
                'sequence' => ((int) ($nextSequence[$id] ?? 0)) + 1,
                'type' => 'status_migrated',
                'from_status' => $old,
                'to_status' => $new,
                'actor_type' => 'system',
                'actor_id' => null,
                'reason_code' => 'state_machine_v2',
                'context' => null,
                'transition_key' => 'migration:state_machine_v2',
                'occurred_at' => $now,
            ], $ids));
        });
    }

    /**
     * @param  list<string>  $states
     */
    private function fillGuard(string $owner, string $guard, array $states, string $duplicateReason, $now): void
    {
        $rows = DB::table('rides')->whereIn('status', $states)->whereNotNull($owner)
            ->orderByDesc('id')->get(['id', $owner]);

        $seen = [];

        foreach ($rows as $row) {
            $holder = $row->{$owner};

            if (! isset($seen[$holder])) {
                $seen[$holder] = true;
                DB::table('rides')->where('id', $row->id)->update([$guard => $holder]);

                continue;
            }

            $sequence = (int) DB::table('ride_events')->where('ride_id', $row->id)->max('sequence') + 1;

            DB::table('ride_events')->insert([
                'ride_id' => $row->id,
                'sequence' => $sequence,
                'type' => 'migration_conflict',
                'from_status' => null,
                'to_status' => null,
                'actor_type' => 'system',
                'actor_id' => null,
                'reason_code' => $duplicateReason,
                'context' => json_encode([$owner => $holder]),
                'transition_key' => 'migration:'.$duplicateReason,
                'occurred_at' => $now,
            ]);
        }
    }
};
