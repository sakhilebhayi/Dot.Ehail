<?php

namespace App\Domain\Earnings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncentiveProgress extends Model
{
    protected $table = 'incentive_progress';

    protected $guarded = ['id'];

    protected $casts = [
        'progress' => 'array',
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'earned_at' => 'datetime',
        'evaluated_at' => 'datetime',
        'percent' => 'integer',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(IncentiveProgram::class, 'incentive_program_id');
    }
}
