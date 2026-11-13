<?php

namespace App\Http\Controllers;

use App\Services\MatchDetailService;
use Illuminate\Http\Request;

/** Resumo da partida (Minhas partidas): na janela da lista vem só o pedaço; aberto direto, a página inteira. */
class MatchDetailController extends Controller
{
    public function show(Request $request, MatchDetailService $details, string $type, int $id)
    {
        $detail = $details->for($type, $id, $request->user());
        abort_unless($detail, 404);
        $view = $request->ajax() ? 'matches.partials.detail' : 'matches.detail';

        return response()->view($view, ['detail' => $detail])->header('Cache-Control', 'private, no-store');
    }
}
