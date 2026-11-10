<?php

namespace App\Domain\Geo\Http\Controllers\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The app renders exceptions as JSON only under /api/*, so these JSON endpoints answer validation failures
 * themselves: 422 with Laravel's usual {message, errors} body, never a redirect.
 */
trait ValidatesJson
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validateJson(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422));
        }

        return $validator->validated();
    }
}
