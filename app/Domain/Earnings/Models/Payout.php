<?php

namespace App\Domain\Earnings\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money owed to one payee in one batch: available earnings minus cash commission they hold.
 */
class Payout extends Model
{
    public const SCHEDULED = 'scheduled';

    public const PROCESSING = 'processing';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const REVERSED = 'reversed';

    protected $guarded = ['id'];

    protected $casts = [
        'earnings_minor' => 'integer',
        'cash_offset_minor' => 'integer',
        'amount_minor' => 'integer',
        'composition' => 'array',
        'paid_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_user_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class, 'payout_account_id');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(DriverEarning::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->payout_batch_id,
            'period_end' => $this->batch?->period_end?->toDateString(),
            'payee_type' => $this->payee_type,
            'currency' => $this->currency,
            'earnings_minor' => $this->earnings_minor,
            'cash_offset_minor' => $this->cash_offset_minor,
            'amount_minor' => $this->amount_minor,
            'status' => $this->status,
            'reference' => $this->reference,
            'failure_reason' => $this->failure_reason,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'composition' => $this->composition,
            'account' => $this->account?->toPublicArray(),
        ];
    }
}
