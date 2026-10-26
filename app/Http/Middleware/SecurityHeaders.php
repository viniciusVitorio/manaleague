<?php

namespace App\Http\Middleware;

use App\Services\AvatarService;
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
        ."script-src 'self' 'unsafe-inline'; "   // handlers inline simples (copiar link, confirm())
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: https://cards.scryfall.io%AVATARS%; "   // cartas oficiais + bucket das fotos de perfil
        ."font-src 'self'; "
        ."connect-src 'self'; "
        ."object-src 'none'; "
        ."base-uri 'self'; "
        ."form-action 'self'; "
        ."frame-ancestors 'self'";

    public function __construct(private readonly AvatarService $avatars) {}

    /** Fotos de perfil vem do bucket S3 (URL assinada) quando AVATAR_DISK=s3; so essa origem e liberada. */
    private function csp(): string
    {
        $origin = $this->avatars->cspOrigin();

        return str_replace('%AVATARS%', $origin ? ' '.$origin : '', self::CSP);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'Content-Security-Policy' => $this->csp(),
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
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

        // HTML contains session-dependent controls and CSRF tokens; never persist it in shared caches.
        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('X-ManaLeague-Assets', \App\Support\VersionedAsset::coreRelease());
        }

        return $response;
    }
}
