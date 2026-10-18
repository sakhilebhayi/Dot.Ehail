<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only: rows are written once through record() and never changed or
 * removed by the application. The guard is at the model layer (the
 * database user can still alter rows; protect that with grants if the
 * threat model needs it).
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['action', 'actor_user_id', 'subject_type', 'subject_id', 'meta'];

    protected $casts = ['meta' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log rows are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit log rows are append-only.'));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(string $action, ?User $actor, Model $subject, array $meta = []): self
    {
        return static::create([
            'action' => $action,
            'actor_user_id' => $actor?->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'meta' => $meta,
        ]);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
