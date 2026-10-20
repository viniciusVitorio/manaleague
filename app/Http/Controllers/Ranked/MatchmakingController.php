<?php

namespace App\Http\Controllers\Ranked;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ranked\JoinQueueRequest;
use App\Models\MatchmakingProposal;
use App\Services\Ranked\MatchmakingService;
use Illuminate\Http\Request;

class MatchmakingController extends Controller
{
    public function __construct(private readonly MatchmakingService $queue) {}

    public function join(JoinQueueRequest $request)
    {
        $data = $request->validated();
        $this->queue->join($request->user(), $data['format'], isset($data['club_id']) ? (int) $data['club_id'] : null, $data['session_note'] ?? null);

        return redirect()->route('ranked.index')->with('status', 'Você entrou na fila. Deixe esta página aberta para continuar disponível.');
    }

    public function leave(Request $request)
    {
        $this->queue->leave($request->user());

        return redirect()->route('ranked.index')->with('status', 'Você saiu da fila.');
    }

    public function status(Request $request)
    {
        return response()->json($this->queue->status($request->user()))->header('Cache-Control', 'private, no-store');
    }

    /** Presenca da pagina aberta: mantem a entrada viva e roda o pareamento. */
    public function heartbeat(Request $request)
    {
        return response()->json($this->queue->heartbeat($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function accept(Request $request, MatchmakingProposal $proposal)
    {
        $p = $this->queue->accept($proposal, $request->user());
        if ($request->expectsJson()) {
            return response()->json(['status' => $p->status, 'match_url' => $p->ranked_match_id ? '/ranked/matches/'.$p->ranked_match_id : null]);
        }

        return $p->ranked_match_id ? redirect('/ranked/matches/'.$p->ranked_match_id)->with('status', 'Partida ranqueada criada. Bom jogo!')
            : back()->with('status', 'Você aceitou. Aguardando o adversário.');
    }

    public function decline(Request $request, MatchmakingProposal $proposal)
    {
        $this->queue->decline($proposal, $request->user());

        return $request->expectsJson() ? response()->json(['status' => 'declined']) : back()->with('status', 'Proposta recusada. Você saiu da fila.');
    }
}
