<?php

namespace App\Domain\Ledger;

use LogicException;

class UnbalancedJournal extends LogicException
{
    public static function for(JournalEntry $entry): self
    {
        return new self(sprintf(
            'Journal [%s] does not balance: debits %d, credits %d.',
            $entry->idempotencyKey,
            $entry->totalDebits(),
            $entry->totalCredits(),
        ));
    }
}
