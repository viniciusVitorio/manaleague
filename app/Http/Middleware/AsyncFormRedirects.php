<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Formularios assincronos (public/js/async-forms.js).
 *
 * O navegador envia o formulario com fetch() e o cabecalho X-ManaLeague-Async: 1. O controller continua
 * igual (redirect()->back()->with('status', ...), erros de validacao etc.); aqui o redirect vira
 * 200 {"redirect": url}. Assim o fetch NAO segue o redirect e a mensagem flash continua na sessao
 * para a proxima pagina: o script busca essa pagina e atualiza a tela sem recarregar (mesma URL)
 * ou navega normalmente (outra URL).
 */
final class AsyncFormRedirects
{
    public const HEADER = 'X-ManaLeague-Async';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->headers->get(self::HEADER) !== '1'
            || ! $response->isRedirection()
            || ! $response->headers->has('Location')) {
            return $response;
        }

        $json = new JsonResponse(['redirect' => $response->headers->get('Location')]);
        foreach ($response->headers->getCookies() as $cookie) {
            $json->headers->setCookie($cookie);
        }
        $json->headers->set('Cache-Control', 'no-store, private');
        $json->setVary(self::HEADER, false);

        return $json;
    }
}
