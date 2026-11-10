<?php

namespace App\Domain\Payments\Models;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment attempt of any purpose (ride fare, cancellation fee, card setup) through any gateway.
 * Same table as the legacy App\Models\RidePayment (ride_payments), extended additively; legacy
 * checkout rows are purpose ride_fare, attempt 1. Amounts are integer minor units (amount_cents).
 */
class Payment extends Model
{
    protected $table = 'ride_payments';

    public const INITIATED = 'initiated';   // checkout opened, payer not back yet (legacy + card setup)

    public const PENDING = 'pending';       // charge sent; no final answer yet

    public const PAID = 'paid';             // verified with the gateway (or cash confirmed by the driver)

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const UNMATCHED = 'unmatched';

    public const AWAITING_CASH = 'awaiting_cash';

    public const PURPOSE_RIDE_FARE = 'ride_fare';

    public const PURPOSE_CANCELLATION_FEE = 'cancellation_fee';

    public const PURPOSE_CARD_SETUP = 'card_setup';

    protected $guarded = ['id'];

    protected $casts = [
        'amount_cents' => 'integer',
        'refunded_minor' => 'integer',
        'attempt' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'verified_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'summary' => 'array',
    ];

    protected $hidden = ['authorization_url'];

    public function amountMinor(): int
    {
        return (int) $this->amount_cents;
    }

    public function refundableMinor(): int
    {
        return max(0, $this->amountMinor() - (int) $this->refunded_minor);
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'payment_id');
    }

    public function scopePurpose(Builder $query, string $purpose): Builder
    {
        return $query->where('purpose', $purpose);
    }
}
