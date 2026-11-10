<?php

namespace App\Domain\Safety\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone a rider or driver wants told when they press SOS or share a trip. Usable only once the
 * contact has confirmed through the emailed link, and never after they opted out. Name, email and
 * phone are encrypted at rest.
 */
class TrustedContact extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['email_hash', 'confirm_token_hash'];

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'confirmation_sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'opted_out_at' => 'datetime',
        ];
    }

    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNotNull('confirmed_at')->whereNull('opted_out_at');
    }

    public function isUsable(): bool
    {
        return $this->confirmed_at !== null && $this->opted_out_at === null;
    }

    public function state(): string
    {
        return match (true) {
            $this->opted_out_at !== null => 'opted_out',
            $this->confirmed_at !== null => 'confirmed',
            default => 'awaiting_confirmation',
        };
    }

    /** "Thandi (t***@example.com)": enough for the owner and ops to recognise, no full address. */
    public function maskedLabel(): string
    {
        $email = (string) $this->email;
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr((string) $this->name, 0, 40).' ('.mb_substr($local, 0, 1).'***@'.$domain.')';
    }

    /** @return array<string, mixed> */
    public function toOwnerArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'relationship' => $this->relationship,
            'state' => $this->state(),
            'confirmation_sent_at' => $this->confirmation_sent_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
        ];
    }
}
