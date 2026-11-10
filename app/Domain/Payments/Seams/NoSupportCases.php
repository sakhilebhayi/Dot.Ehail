<?php

namespace App\Domain\Payments\Seams;

use App\Models\Ride;
use App\Models\User;

class NoSupportCases implements SupportCaseOpener
{
    public function open(string $kind, ?Ride $ride, User $raisedBy, string $summary): ?string
    {
        return null;
    }
}
