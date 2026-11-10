<?php

namespace App\Domain\Ops\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operator role held by one person. Granted and removed only through RoleAdministration (audited).
 *
 * @property int $id
 * @property int $user_id
 * @property string $role
 * @property int|null $granted_by
 */
class OpsRoleAssignment extends Model
{
    protected $fillable = ['user_id', 'role', 'granted_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function roleEnum(): ?OpsRole
    {
        return OpsRole::tryFrom($this->role);
    }
}
