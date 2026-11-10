<?php

namespace App\Http\Controllers\Business\Concerns;

use App\Domain\Payments\Money;

/** Amounts typed in rand ("1250" or "1250.50") become integer minor units; empty stays null (no limit). */
trait ReadsRand
{
    protected function minorOrNull(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return max(0, Money::decimalToMinor(str_replace(',', '.', (string) $value)));
    }

    /** Validation rule for a rand amount field. */
    protected function randRule(): array
    {
        return ['nullable', 'regex:/^\d{1,9}([.,]\d{1,2})?$/'];
    }
}
