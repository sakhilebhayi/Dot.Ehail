<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One debit or credit line. Append-only.
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'debit_minor' => 'integer',
        'credit_minor' => 'integer',
        'party_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are immutable.'));
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(LedgerJournal::class, 'journal_id');
    }
}
