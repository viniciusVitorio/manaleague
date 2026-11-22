<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Link de confirmação legítimo, mas vencido, leva à tela de reenvio em vez de um erro 403.
 * Assinatura adulterada continua barrada pelo middleware signed, que roda depois deste.
 */
class RedirectExpiredVerificationLink
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! URL::hasCorrectSignature($request) || URL::signatureHasNotExpired($request)) {
            return $next($request);
        }

        $user = $request->user();
        $redirect = redirect()->route('verification.notice');

        return $user->email_verification_required && ! $user->hasVerifiedEmail()
            ? $redirect->withErrors(['email' => 'Esse link de confirmação venceu. Peça um novo abaixo.'])
            : $redirect;
    }
}
