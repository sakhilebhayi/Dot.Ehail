<?php

namespace App\Domain\Dispatch\Seams;

use App\Domain\Dispatch\Contracts\DriverLocationSink;
use App\Models\User;
use Illuminate\Support\Carbon;

class NullLocationSink implements DriverLocationSink
{
    public function record(User $driver, float $lat, float $lng, ?int $heading, ?float $speedMs, ?float $accuracyM, Carbon $recordedAt): void {}
}
