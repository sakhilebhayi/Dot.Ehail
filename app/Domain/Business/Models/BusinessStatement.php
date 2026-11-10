<?php

namespace App\Domain\Business\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A monthly statement, frozen at issue: opening balance, charges, payments and closing balance are the
 * ledger's figures for business_receivable:{account} over the period; lines are the charges posted in it.
 */
class BusinessStatement extends Model
{
    public const ISSUED = 'issued';

    public const PAID = 'paid';

    public const OVERDUE = 'overdue';

    public const NOTHING_DUE = 'nothing_due';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'opening_minor' => 'integer',
            'charges_minor' => 'integer',
            'payments_minor' => 'integer',
            'closing_minor' => 'integer',
            'tax_minor' => 'integer',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'reminded_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(BusinessCharge::class, 'statement_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BusinessPayment::class, 'statement_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::ISSUED => 'Due',
            self::PAID => 'Paid',
            self::OVERDUE => 'Overdue',
            self::NOTHING_DUE => 'Nothing due',
            default => ucfirst((string) $this->status),
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::PAID, self::NOTHING_DUE => 'go',
            self::OVERDUE => 'stop',
            default => 'caution',
        };
    }
}
