<?php

namespace App\Domain\Dispatch\Seams;

use App\Domain\Dispatch\Contracts\ServiceAreaCheck;

/**
 * Default ServiceAreaCheck: no service areas exist until the geo domain is
 * merged, so the answer is "unknown" (null) and dispatch does not filter.
 */
class UnknownServiceArea implements ServiceAreaCheck
{
    public function covers(float $lat, float $lng): ?bool
    {
        return null;
    }
}
