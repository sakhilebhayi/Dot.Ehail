<?php

namespace App\Policies;

use App\Models\DriverDocument;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Same visibility rule as DriverProfilePolicy::view() -- a document is
 * visible to the driver who uploaded it and their fleet owner (reviewing
 * an application), nobody else.
 */
class DriverDocumentPolicy
{
    use HandlesAuthorization;

    public function view(User $user, DriverDocument $document): bool
    {
        // DriverProfile carries HasUserScope -- the lazy-loaded
        // $document->driverProfile relation silently resolves to null for
        // any viewer who isn't that profile's own owner (the global scope
        // filters the query to Auth::id() first), which would make this
        // check always fail for the one case it exists to allow: a fleet
        // owner viewing someone else's document. Bypass it explicitly,
        // same as FleetController's own withoutGlobalScope('user') calls.
        $profile = $document->driverProfile()->withoutGlobalScope('user')->first();

        if (! $profile) {
            return false;
        }

        if ($user->id === $profile->user_id || $user->isPlatformOperator()) {
            return true;
        }

        // R-46 (D-1205): a fleet owner sees an applicant's identity documents only when a platform operator has
        // granted the fleet identity-review rights; by default the fleet sees document status, never contents.
        return $profile->fleet
            && $user->id === $profile->fleet->owner_user_id
            && $profile->fleet->identityReviewGranted();
    }

    /**
     * Unlike view(), a fleet owner may not delete an applicant's document
     * -- only the driver who uploaded it.
     */
    public function delete(User $user, DriverDocument $document): bool
    {
        $profile = $document->driverProfile()->withoutGlobalScope('user')->first();

        return $profile !== null && $user->id === $profile->user_id;
    }
}
