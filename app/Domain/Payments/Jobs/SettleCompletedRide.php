<?php

namespace App\Domain\Payments\Jobs;

use App\Domain\Payments\RideSettlement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Settle a completed ride's money (RideSettlement). Unique per ride while queued; idempotent anyway.
 */
class SettleCompletedRide implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * The unique lock expires (it never did before): a job lost after it was queued (a worker killed mid-job,
     * a jobs row removed) would otherwise block every later settlement of that ride. ehail:settlement-sweep
     * waits longer than this before it queues settlement again.
     */
    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public int $rideId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'settle-ride-'.$this->rideId;
    }

    public function handle(RideSettlement $settlement): void
    {
        $outcome = $settlement->onTripCompleted($this->rideId);

        // One line per settlement run: the trail that shows a completed ride's money was handled.
        Log::info('ehail.settlement', ['ride_id' => $this->rideId, 'outcome' => $outcome]);
    }
}
