<?php

namespace App\Domain\Earnings\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payee's bank account. Holder name and account number are encrypted at rest; the keyed blind
 * index lets the platform spot one account used by two payees without storing the number in clear.
 */
class PayoutAccount extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['account_holder', 'account_number', 'account_number_index', 'provider_recipient_code'];

    protected $casts = [
        'account_holder' => 'encrypted',
        'account_number' => 'encrypted',
        'verified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public static function blindIndex(string $accountNumber, string $bankCode): string
    {
        $normalised = preg_replace('/\D/', '', $accountNumber).'|'.trim($bankCode);

        return hash_hmac('sha256', $normalised, 'ehail.payout-account-index|'.config('app.key'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'payee_type' => $this->payee_type,
            'fleet_id' => $this->fleet_id,
            'bank_name' => $this->bank_name,
            'bank_code' => $this->bank_code,
            'account_type' => $this->account_type,
            'account_masked' => '•••• '.$this->account_last4,
            'verification_status' => $this->verification_status,
            'verification_message' => $this->verification_message,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'is_active' => $this->is_active,
        ];
    }
}
