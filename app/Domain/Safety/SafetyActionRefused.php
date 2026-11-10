<?php

namespace App\Domain\Safety;

use DomainException;
use Illuminate\Http\JsonResponse;

/** An action the current state does not allow (rendered as 422 with the reason). */
class SafetyActionRefused extends DomainException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
