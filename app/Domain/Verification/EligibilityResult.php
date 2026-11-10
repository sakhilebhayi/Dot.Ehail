<?php

namespace App\Domain\Verification;

/**
 * Answer to "may this driver go online in this vehicle?". allowed is true only when reasons is
 * empty. Each reason is machine-readable (code, subject, type) with a sentence for the driver.
 */
final readonly class EligibilityResult
{
    /**
     * @param  list<array{code: string, subject: string, type: ?string, message: string}>  $reasons
     */
    public function __construct(public array $reasons, public bool $documentsEnforced = true) {}

    public function allowed(): bool
    {
        return $this->reasons === [];
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_values(array_unique(array_map(fn (array $r) => $r['code'], $this->reasons)));
    }

    /**
     * @return array{allowed: bool, documents_enforced: bool, reasons: list<array{code: string, subject: string, type: ?string, message: string}>}
     */
    public function toArray(): array
    {
        return ['allowed' => $this->allowed(), 'documents_enforced' => $this->documentsEnforced, 'reasons' => $this->reasons];
    }
}
