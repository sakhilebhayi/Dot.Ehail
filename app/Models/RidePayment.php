<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment attempt for a ride's fare (or an unmatched charge Paystack reported). Amounts are
 * integer cents. Written only by App\Services\Ehail\RidePayments.
 */
class RidePayment extends Model
{
    public const STATUS_INITIATED = 'initiated';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_UNMATCHED = 'unmatched';

    public const REASONS = [
        'unknown_reference' => 'Reference not recognised',
        'amount_mismatch' => 'Amount did not match the fare',
        'currency_mismatch' => 'Currency did not match',
        'reference_mismatch' => 'Reference did not match the attempt',
        'duplicate_payment' => 'Ride was already paid',
        'duplicate_transaction' => 'Transaction already used for another payment',
        'ride_not_payable' => 'Ride was waived or had no fare due',
        'fare_changed' => 'Fare changed after the attempt started',
    ];

    protected $fillable = [
        'ride_id', 'user_id', 'provider', 'reference', 'amount_cents', 'currency', 'status',
        'provider_transaction_id', 'authorization_url', 'expires_at', 'paid_at', 'summary',
        'unmatched_reason', 'reviewed_at', 'reviewed_by', 'review_note',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'summary' => 'array',
    ];

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_UNMATCHED)->whereNull('reviewed_at');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->unmatched_reason] ?? 'Needs a look';
    }
}
