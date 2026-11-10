<?php

namespace App\Domain\Verification\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of everything that happened to a verification document: submitted,
 * assigned, approved, rejected, resubmission requested, expiry warnings, expired, superseded.
 */
class VerificationReview extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Verification history is append-only.'));
        static::deleting(fn () => throw new LogicException('Verification history is append-only.'));
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(VerificationDocument::class, 'verification_document_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
