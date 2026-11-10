<?php

namespace App\Domain\Safety\Models;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A public, expiring, revocable link to follow one trip. Only the SHA-256 of the token is kept;
 * the token itself is shown once to its creator (and emailed if sent to a trusted contact).
 */
class TripShare extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function trustedContact(): BelongsTo
    {
        return $this->belongsTo(TrustedContact::class);
    }
}
