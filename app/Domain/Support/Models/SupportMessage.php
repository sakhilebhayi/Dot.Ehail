<?php

namespace App\Domain\Support\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message on a case. visibility: participants (requester, agents, and the counterparty on a
 * lost-property case), requester (requester and agents only) or internal (agents only).
 * Messages are never edited.
 */
class SupportMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['attachment_disk', 'attachment_path'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'attachment_size' => 'integer'];
    }

    public function supportCase(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
