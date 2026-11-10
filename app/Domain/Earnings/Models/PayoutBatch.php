<?php

namespace App\Domain\Earnings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutBatch extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'period_end' => 'date',
        'exported_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_minor' => 'integer',
        'payout_count' => 'integer',
        'summary' => 'array',
    ];

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }
}
