<?php

namespace App\Domain\Verification;

use App\Domain\Verification\Models\VerificationDocument;
use App\Models\User;

/**
 * Who may see a verification document: the driver it belongs to, the reviewer it is assigned
 * to, and platform operators (the authorised operations role). Fleet owners are deliberately not
 * included for these documents (identity numbers and photos; POPIA minimisation, D-503).
 */
class VerificationDocumentPolicy
{
    public function view(User $user, VerificationDocument $document): bool
    {
        return $user->id === $document->user_id
            || $user->id === $document->assigned_reviewer_id
            || $user->isPlatformOperator();
    }

    public function review(User $user, VerificationDocument $document): bool
    {
        return $user->isPlatformOperator() && $user->id !== $document->user_id;
    }
}
