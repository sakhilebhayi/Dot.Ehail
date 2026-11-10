<?php

namespace App\Domain\Payments\Models;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    public const REQUESTED = 'requested';

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const PROCESSED = 'processed';

    public const FAILED = 'failed';

    public const REASONS = [
        'overcharged' => 'Passenger was overcharged',
        'route_issue' => 'Route or distance problem',
        'driver_issue' => 'Driver conduct or service',
        'safety_incident' => 'Safety incident',
        'cancelled_trip' => 'Trip did not happen',
        'duplicate_charge' => 'Charged twice',
        'goodwill' => 'Goodwill gesture',
        'card_setup_verification' => 'Card verification charge returned',
        'other' => 'Other (see note)',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'amount_minor' => 'integer',
        'charge_back_driver' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::REQUESTED, self::PENDING, self::PROCESSING], true);
    }
}
