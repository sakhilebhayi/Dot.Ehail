<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grants or withdraws the platform-operator flag, the one thing no screen can do for the first operator
 * (users.is_platform_operator is never mass-assignable and SSO never sets it). Roles inside the operations centre
 * are then given in /ops/roles; an operator with no role rows keeps full access until an admin assigns roles.
 * Every change is an audit_logs row (actor: none, the console).
 */
class MakePlatformOperator extends Command
{
    protected $signature = 'ehail:operator
        {email : The person\'s email address (they must have signed in once)}
        {--revoke : Withdraw the flag instead of granting it}';

    protected $description = 'Make an existing user a platform operator (or --revoke it)';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            $this->error("No user with the email {$email}. They must sign in once (through InfoDot) before they can be made an operator.");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');

        if ((bool) $user->is_platform_operator === $grant) {
            $this->info($grant ? "{$email} is already a platform operator." : "{$email} is not a platform operator.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_platform_operator' => $grant])->save();
        AuditLog::record($grant ? 'ops.operator_granted' : 'ops.operator_revoked', null, $user, ['via' => 'console']);

        $this->info($grant
            ? "{$email} is now a platform operator. Assign roles in /ops/roles (without roles an operator has full access)."
            : "{$email} is no longer a platform operator.");

        return self::SUCCESS;
    }
}
