<?php

namespace App\Domain\Payments\Jobs;

use App\Domain\Payments\CancellationFees;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

class ChargeCancellationFee implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $rideId, public string $actor, public string $occurredAt)
    {
        $this->afterCommit();
    }

    public function handle(CancellationFees $fees): void
    {
        $fees->onRideCancelled($this->rideId, $this->actor, Carbon::parse($this->occurredAt));
    }
}
