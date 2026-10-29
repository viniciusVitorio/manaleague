<?php

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
        $middleware->validateCsrfTokens(except: []);
        // AsyncFormRedirects fica por fora de todo o grupo web: converte tambem os redirects de auth/guest.
        $middleware->web(
            prepend: [\App\Http\Middleware\AsyncFormRedirects::class],
            append: [\App\Http\Middleware\SecurityHeaders::class],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Central point for future exception reporting.
    })->create();
