<?php

namespace App\Domain\Payments\Models;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An issued receipt: a sequence number and a frozen snapshot of what the passenger paid. Never
 * changed after issue (model guard and a database trigger).
 */
class Receipt extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot' => 'array',
        'issued_at' => 'datetime',
        'total_minor' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Receipts are immutable.'));
        static::deleting(fn () => throw new LogicException('Receipts are immutable.'));
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_id');
    }
}
