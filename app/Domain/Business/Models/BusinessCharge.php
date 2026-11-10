<?php

namespace App\Domain\Business\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One amount billed to the account through money's business_account gateway (a fare or a cancellation
 * fee). The ledger posts it as payment-settled:{payment_id} (Dr business_receivable:{account}).
 */
class BusinessCharge extends Model
{
    public const RIDE_FARE = 'ride_fare';

    public const CANCELLATION_FEE = 'cancellation_fee';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'tax_minor' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(BusinessTrip::class, 'business_trip_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }
}
