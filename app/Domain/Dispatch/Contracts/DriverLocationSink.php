<?php

namespace App\Domain\Dispatch\Contracts;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Where a heartbeat's position is forwarded besides the dispatch index: the
 * geo domain's LocationStore::record (trails, deviation detection, retention
 * rules). The default binding forwards nowhere.
 */
interface DriverLocationSink
{
    public function record(User $driver, float $lat, float $lng, ?int $heading, ?float $speedMs, ?float $accuracyM, Carbon $recordedAt): void;
}
