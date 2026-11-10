<?php

namespace App\Domain\Payments\Models;

use App\Models\Ride;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How the passenger chose to pay for one ride (card, cash or business account), set before or
 * during the trip. With no choice the passenger's default card is used, else the checkout link.
 */
class RidePaymentChoice extends Model
{
    public const CARD = 'card';

    public const CASH = 'cash';

    public const BUSINESS_ACCOUNT = 'business_account';

    protected $guarded = ['id'];

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
