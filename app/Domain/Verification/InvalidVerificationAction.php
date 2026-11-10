<?php

namespace App\Domain\Verification;

use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * A verification action that the document's current state does not allow (approving a rejected
 * document, reviewing your own document, approving without a required expiry date...).
 */
class InvalidVerificationAction extends DomainException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
