<?php

namespace App\Domain\Business\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received from the business against its receivable: an EFT an operator recorded, or a charge to
 * the company card through Paystack (paid only after Paystack's verify says so).
 */
class BusinessPayment extends Model
{
    public const EFT = 'eft';

    public const CARD = 'card';

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'paid_at' => 'datetime', 'attempt' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BusinessStatement::class, 'statement_id');
    }
}
