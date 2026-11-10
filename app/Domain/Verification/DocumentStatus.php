<?php

namespace App\Domain\Verification;

/**
 * Status of one uploaded verification document.
 *
 *   pending -> approved | rejected | resubmission_requested
 *   approved -> expired (expiry date passed) | superseded (a newer one was approved)
 *   pending | rejected | resubmission_requested -> superseded (a newer one was uploaded)
 */
enum DocumentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ResubmissionRequested = 'resubmission_requested';
    case Expired = 'expired';
    case Superseded = 'superseded';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::ResubmissionRequested, self::Superseded],
            self::Approved => [self::Expired, self::Superseded],
            self::Rejected, self::ResubmissionRequested => [self::Superseded],
            self::Expired, self::Superseded => [],
        };
    }

    public function canBecome(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isCurrent(): bool
    {
        return $this !== self::Superseded;
    }
}
