<?php

namespace App\Domain\Business\Models;

use App\Domain\Payments\Models\PaymentMethod;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company that rides on account. Only an operator moves it out of pending_review; only an active
 * account can have new rides billed to it (an account suspended for an overdue statement still pays
 * for trips already booked).
 */
class BusinessAccount extends Model
{
    public const PENDING = 'pending_review';

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const DECLINED = 'declined';

    public const CLOSED = 'closed';

    public const INVOICE = 'invoice';

    public const CARD = 'card';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'credit_limit_minor' => 'integer',
            'terms_days' => 'integer',
            'grace_days' => 'integer',
            'reviewed_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(BusinessMember::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(BusinessDepartment::class);
    }

    public function policies(): HasMany
    {
        return $this->hasMany(BusinessPolicy::class);
    }

    public function statements(): HasMany
    {
        return $this->hasMany(BusinessStatement::class);
    }

    public function cardMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'card_payment_method_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function displayName(): string
    {
        return $this->trading_name ?: $this->legal_name;
    }

    public function defaultPolicy(): ?BusinessPolicy
    {
        return $this->policies()->where('is_default', true)->first() ?? $this->policies()->orderBy('id')->first();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => 'Waiting for review',
            self::ACTIVE => 'Active',
            self::SUSPENDED => 'Suspended',
            self::DECLINED => 'Declined',
            self::CLOSED => 'Closed',
            default => ucfirst((string) $this->status),
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::ACTIVE => 'go',
            self::PENDING => 'caution',
            default => 'stop',
        };
    }
}
