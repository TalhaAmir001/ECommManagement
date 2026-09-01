<?php

use App\Http\Middleware\StripAppSubpath;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        // Run very early so routing sees the stripped path. When APP_URL
        // has no subpath (e.g. http://localhost in tests), this is a
        // no-op.
        $middleware->prepend(StripAppSubpath::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
