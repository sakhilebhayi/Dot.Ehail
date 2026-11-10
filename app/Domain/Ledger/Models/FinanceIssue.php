<?php

namespace App\Domain\Ledger\Models;

use App\Models\Ride;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An item on the finance operations exceptions list: something money-related a person must look at
 * (unmatched charge, cash discrepancy, failed payout, reconciliation mismatch, exhausted retries).
 */
class FinanceIssue extends Model
{
    public const KINDS = [
        'unmatched_payment' => 'Charge not matched to a payment',
        'cash_discrepancy' => 'Cash received differs from the fare',
        'payment_retries_exhausted' => 'Card declined after every retry',
        'payout_failed' => 'Payout failed',
        'payout_reversed' => 'Payout reversed by the bank',
        'refund_failed' => 'Refund failed',
        'reconciliation_mismatch' => 'Gateway settlement differs from the ledger',
        'ledger_imbalance' => 'Ledger journal does not balance',
        'duplicate_payout_account' => 'Bank account used by more than one payee',
        'card_setup_refund' => 'Card verification charge not refunded',
        'webhook_failed' => 'Webhook could not be processed',
        'settlement_missing' => 'Completed ride was never settled',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'details' => 'array',
        'resolved_at' => 'datetime',
        'amount_minor' => 'integer',
    ];

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
