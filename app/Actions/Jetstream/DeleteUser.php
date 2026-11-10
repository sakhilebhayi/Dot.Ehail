<?php

namespace App\Actions\Jetstream;

use App\Domain\Security\AccountClosure;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Create a new action instance.
     */
    public function __construct(protected DeletesTeams $deletesTeams) {}

    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        // A person with ride, money or safety history is anonymised, not deleted: those records must stay and the
        // ledger is immutable (R-46 security S-21, docs/R46_PRIVACY.md section 6).
        $closure = app(AccountClosure::class);

        if ($closure->hasHistory($user)) {
            $closure->assertClosable($user);
            $user->deleteProfilePhoto();
            $closure->anonymise($user);
            $user->ownedTeams->each(fn (Team $team) => $this->deletesTeams->delete($team));

            return;
        }

        DB::transaction(function () use ($user) {
            $this->deleteTeams($user);
            $user->deleteProfilePhoto();
            $user->tokens->each->delete();
            $user->delete();
        });
    }

    /**
     * Delete the teams and team associations attached to the user.
     */
    protected function deleteTeams(User $user): void
    {
        $user->teams()->detach();

        $user->ownedTeams->each(function (Team $team) {
            $this->deletesTeams->delete($team);
        });
    }
}
