<?php

namespace App\Domain\Business\Data;

use App\Domain\Business\Models\BusinessApproval;

/**
 * The outcome of checking a ride: refused (reasons), needs an approver (approval reasons), or allowed.
 * Every reason is {code, message}, with a message written for the person booking.
 */
final class PolicyDecision
{
    /**
     * @param  list<array{code: string, message: string}>  $refusals
     * @param  list<array{code: string, message: string}>  $approvalReasons
     */
    public function __construct(
        public readonly array $refusals,
        public readonly array $approvalReasons = [],
        public readonly ?BusinessApproval $approval = null,
    ) {}

    /** True when an approver's yes covers this ride (the approval is then used by the booking). */
    public function approvedByRequest(): bool
    {
        return $this->approval !== null;
    }

    public function allowed(): bool
    {
        return $this->refusals === [] && $this->approvalReasons === [];
    }

    public function needsApproval(): bool
    {
        return $this->refusals === [] && $this->approvalReasons !== [];
    }

    public function refused(): bool
    {
        return $this->refusals !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed(),
            'needs_approval' => $this->needsApproval(),
            'reasons' => $this->refused() ? $this->refusals : $this->approvalReasons,
            'approval' => $this->approval?->toPublicArray(),
        ];
    }
}
