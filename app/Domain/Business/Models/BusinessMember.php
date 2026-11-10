<?php

namespace App\Domain\Business\Models;

use App\Domain\Payments\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person on a business account. Invited by email; becomes active when a signed-in user with that
 * email accepts. Active members who may ride get a business_account payment method (money's seam)
 * that the passenger app offers as their business profile.
 */
class BusinessMember extends Model
{
    public const ADMIN = 'account_admin';

    public const MANAGER = 'travel_manager';

    public const FINANCE = 'finance_viewer';

    public const TRAVELLER = 'traveller';

    public const ROLES = [
        self::ADMIN => 'Account admin',
        self::MANAGER => 'Travel manager',
        self::FINANCE => 'Finance viewer',
        self::TRAVELLER => 'Traveller',
    ];

    public const INVITED = 'invited';

    public const ACTIVE = 'active';

    public const REMOVED = 'removed';

    protected $guarded = ['id'];

    protected $hidden = ['invite_token_hash'];

    protected function casts(): array
    {
        return [
            'monthly_allowance_minor' => 'integer',
            'invite_expires_at' => 'datetime',
            'joined_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(BusinessDepartment::class, 'department_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(BusinessPolicy::class, 'policy_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Finance viewers read; everyone else may ride on the account. */
    public function mayRide(): bool
    {
        return $this->role !== self::FINANCE;
    }

    public function isApprover(): bool
    {
        return in_array($this->role, [self::ADMIN, self::MANAGER], true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    public function displayName(): string
    {
        return $this->user?->name ?? $this->name ?? $this->email;
    }

    public function effectivePolicy(): ?BusinessPolicy
    {
        return $this->policy ?? $this->account->defaultPolicy();
    }
}
