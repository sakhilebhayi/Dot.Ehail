<?php

namespace App\Domain\Payments\Jobs;

use App\Domain\Payments\RideSettlement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Settle a completed ride's money (RideSettlement). Unique per ride while queued; idempotent anyway.
 */
class SettleCompletedRide implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

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
        $settlement->onTripCompleted($this->rideId);
    }
}
