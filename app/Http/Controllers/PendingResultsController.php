<?php

namespace App\Http\Controllers;

use App\Services\PendingResultsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Confirmar todos": confirma de uma vez os placares que os adversários enviaram. */
class PendingResultsController extends Controller
{
    public function confirmAll(Request $request, PendingResultsService $results): RedirectResponse
    {
        ['confirmed' => $confirmed, 'skipped' => $skipped] = $results->confirmAll($request->user());

        if ($confirmed === 0 && $skipped === 0) {
            return back()->with('status', 'Nenhum placar esperando a sua confirmação.');
        }

        $message = $confirmed === 1 ? '1 placar confirmado.' : "{$confirmed} placares confirmados.";
        if ($skipped) {
            $message .= ' '.($skipped === 1 ? '1 placar não pôde ser confirmado' : "{$skipped} placares não puderam ser confirmados")
                .' porque mudou, foi contestado ou a fase do torneio fechou. Confira um por um.';
        }

        return back()->with('status', $message);
    }
}
