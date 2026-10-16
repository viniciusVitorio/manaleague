<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers de seguranca aplicados pela propria aplicacao (defesa em profundidade):
 * continuam valendo mesmo se o proxy (Caddy/Nginx) for trocado ou mal configurado.
 */
class SecurityHeaders
{
    private const CSP = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval'; "   // WASM do OCR local; sem eval generico
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: https://cards.scryfall.io; "   // imagens oficiais das cartas
        ."font-src 'self'; "
        ."connect-src 'self'; "
        ."object-src 'none'; "
        ."base-uri 'self'; "
        ."form-action 'self'; "
        ."frame-ancestors 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'Content-Security-Policy' => self::CSP,
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(self)',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
