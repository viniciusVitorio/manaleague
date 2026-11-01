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
<<<<<<< Updated upstream
=======
        // Proxy confiavel apenas em desenvolvimento, para testar a camera atras de um tunel HTTPS:
        //   TRUSTED_PROXIES='*' php artisan serve
        // Precisa ser variavel de ambiente de verdade (aqui o .env ainda nao foi lido). Em producao
        // ela nao existe: o IP real chega pelo WAF (X-Real-IP -> REMOTE_ADDR) e confiar em proxy
        // sem necessidade enfraqueceria o throttle por visitante.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }
>>>>>>> Stashed changes
        // AsyncFormRedirects fica por fora de todo o grupo web: converte tambem os redirects de auth/guest.
        $middleware->web(
            prepend: [\App\Http\Middleware\AsyncFormRedirects::class],
            append: [\App\Http\Middleware\SecurityHeaders::class],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Central point for future exception reporting.
    })->create();
