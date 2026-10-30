<?php

use App\Http\Middleware\EnsurePlatformOperator;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'operator' => EnsurePlatformOperator::class,
        ]);

        // Baseline browser protections (enforced headers plus a report-only CSP) on every response.
        $middleware->append(SecurityHeaders::class);

        // Paystack's webhook is server to server and signed; it carries no CSRF token. The browser
        // posts CSP violation reports to /csp-report on its own, also without a token.
        $middleware->preventRequestForgery(except: ['webhooks/paystack', 'csp-report']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
