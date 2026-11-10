<?php

namespace App\Domain\Business\Data;

use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessApproval;
use App\Domain\Business\Models\BusinessDepartment;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessPolicy;
use Carbon\CarbonInterface;

/**
 * One ride as a business sees it, ready to be checked against the policy and the limits.
 * $traveller is the member the ride counts against (the booker when riding themselves); a guest ride
 * counts against the booker.
 */
final class TripRequest
{
    public function __construct(
        public readonly BusinessAccount $account,
        public readonly BusinessMember $booker,
        public readonly BusinessMember $traveller,
        public readonly ?BusinessPolicy $policy,
        public readonly ?BusinessDepartment $department,
        public readonly string $category,
        public readonly float $pickupLat,
        public readonly float $pickupLng,
        public readonly float $dropoffLat,
        public readonly float $dropoffLng,
        public readonly int $fareMinor,
        public readonly string $currency,
        public readonly CarbonInterface $at,
        public readonly ?string $purpose = null,
        public readonly bool $isGuest = false,
        public readonly ?string $guestName = null,
        public readonly ?string $guestPhone = null,
        public readonly ?BusinessApproval $approval = null,
        public readonly ?string $pickupAddress = null,
        public readonly ?string $dropoffAddress = null,
    ) {}

    public function onBehalf(): bool
    {
        return $this->isGuest || $this->traveller->id !== $this->booker->id;
    }
}
