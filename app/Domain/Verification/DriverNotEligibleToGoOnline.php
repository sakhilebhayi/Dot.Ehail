<?php

namespace App\Domain\Verification;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when something tries to set a driver online while DriverEligibility says no. JSON
 * callers get 422 with the structured reasons; page callers go back with the first reason.
 */
class DriverNotEligibleToGoOnline extends RuntimeException
{
    public function __construct(public readonly EligibilityResult $result)
    {
        parent::__construct($result->reasons[0]['message'] ?? 'You cannot go online yet.');
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()] + $this->result->toArray(), 422);
        }

        return back()->withErrors(['online' => $this->getMessage()]);
    }
}
