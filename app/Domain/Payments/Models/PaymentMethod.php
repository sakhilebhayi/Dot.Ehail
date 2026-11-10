<?php

namespace App\Domain\Payments\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved way to pay. Only the provider's token (authorization code, encrypted at rest) and display
 * facts (brand, last four digits, expiry, bank) are kept: never a card number or CVV. Cash is not
 * stored here (it is a choice per ride, see RidePaymentChoice).
 */
class PaymentMethod extends Model
{
    public const CARD = 'card';

    public const BUSINESS_ACCOUNT = 'business_account';

    protected $guarded = ['id'];

    protected $hidden = ['authorization_code', 'signature'];

    protected $casts = [
        'authorization_code' => 'encrypted',
        'reusable' => 'boolean',
        'is_default' => 'boolean',
        'removed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        if ($this->type !== self::CARD || $this->exp_year === null || $this->exp_month === null) {
            return false;
        }

        $at = $at === null ? now() : Carbon::instance($at);
        $endOfExpiryMonth = Carbon::create((int) $this->exp_year, (int) $this->exp_month, 1)->endOfMonth();

        return $at->greaterThan($endOfExpiryMonth);
    }

    public function isChargeable(): bool
    {
        return $this->status === 'active'
            && ($this->type !== self::CARD || ($this->reusable && $this->authorization_code !== null && ! $this->isExpired()));
    }

    /** "Visa •••• 4081", the only way a card is ever shown. */
    public function maskedLabel(): string
    {
        if ($this->type === self::BUSINESS_ACCOUNT) {
            return 'Business account';
        }

        return trim(ucfirst((string) $this->brand).' •••• '.$this->last4);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->maskedLabel(),
            'brand' => $this->brand,
            'last4' => $this->last4,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'bank' => $this->bank,
            'is_default' => $this->is_default,
            'expired' => $this->isExpired(),
            'chargeable' => $this->isChargeable(),
        ];
    }
}
