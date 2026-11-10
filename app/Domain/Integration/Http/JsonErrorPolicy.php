<?php

namespace App\Domain\Integration\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * One JSON error policy for every Dot.Ehail API (D-711, docs/R46_INTEGRATION.md section 4).
 *
 * WHEN: a request gets a JSON error if it asked for JSON (Accept: application/json or XHR) or its path is one
 * of the JSON-only APIs listed in config ehail_integration.json_paths (api/*, pricing, geo lookups...). Every
 * other request keeps Laravel's HTML pages and redirects (login redirect, error pages).
 *
 * SHAPE: {"error": "<machine code>", "message": "<sentence for a person>"} with the status code; validation adds
 * Laravel's "errors" field map; 429 keeps Retry-After. Domain refusals (dispatch DispatchRefused, safety
 * DriverNotEligibleToGoOnline, money PaymentMethodRefused...) render their own codes in the same shape.
 * A 404 never names a model or table.
 */
final class JsonErrorPolicy
{
    private const DEFAULT_MESSAGES = [
        401 => 'Sign in to continue.',
        403 => 'Not allowed.',
        404 => 'Not found.',
        405 => 'That method is not allowed here.',
        419 => 'Your session expired. Reload the page and try again.',
        429 => 'Too many requests. Wait a minute and try again.',
        503 => 'This service is temporarily unavailable. Try again shortly.',
    ];

    private const CODES = [
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        419 => 'session_expired',
        429 => 'too_many_requests',
        503 => 'unavailable',
    ];

    /** Framework wordings replaced by the sentences above (they are not written for a person). */
    private const FRAMEWORK_WORDINGS = ['', 'Forbidden', 'This action is unauthorized.', 'Not Found', 'Unauthenticated.', 'Too Many Attempts.', 'CSRF token mismatch.', 'Page Expired', 'Service Unavailable'];

    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => self::wantsJson($request));

        $exceptions->render(function (ValidationException $e, Request $request) {
            return self::wantsJson($request)
                ? response()->json(['error' => 'validation_failed', 'message' => $e->getMessage(), 'errors' => $e->errors()], $e->status)
                : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return self::wantsJson($request) ? response()->json(['error' => self::CODES[401], 'message' => self::DEFAULT_MESSAGES[401]], 401) : null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! self::wantsJson($request)) {
                return null;
            }

            $status = $e->getStatusCode();

            return response()->json(['error' => self::CODES[$status] ?? 'http_'.$status, 'message' => self::message($e)], $status, $e->getHeaders());
        });
    }

    public static function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->is(...(array) config('ehail_integration.json_paths', ['api/*']));
    }

    private static function message(HttpExceptionInterface $e): string
    {
        $status = $e->getStatusCode();
        $message = trim((string) ($e instanceof \Throwable ? $e->getMessage() : ''));

        if (in_array($message, self::FRAMEWORK_WORDINGS, true) || str_starts_with($message, 'No query results') || str_starts_with($message, 'The route ')) {
            return self::DEFAULT_MESSAGES[$status] ?? 'The request failed.';
        }

        return $status >= 500 && ! array_key_exists($status, self::DEFAULT_MESSAGES) ? 'The request failed.' : $message;
    }
}
