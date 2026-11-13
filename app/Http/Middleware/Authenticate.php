<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;

/** O alias auth também protege módulos e broadcasting, sem depender de cada rota lembrar de verified. */
class Authenticate extends BaseAuthenticate
{
    public function handle($request, Closure $next, ...$guards)
    {
        $this->authenticate($request, $guards);

        if (! $request->routeIs('verification.notice', 'verification.send', 'verification.verify', 'logout')
            && $request->user()->email_verification_required
            && ! $request->user()->hasVerifiedEmail()) {
            if ($request->expectsJson() && $request->header(AsyncFormRedirects::HEADER) !== '1') {
                abort(403, 'Confirme seu e-mail para continuar.');
            }

            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
