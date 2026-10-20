<?php

namespace App\Http\Controllers\Ranked;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ranked\ResolveRankedMatchRequest;
use App\Http\Requests\Ranked\SubmitRankedResultRequest;
use App\Models\RankedMatch;
use App\Services\Ranked\RankedMatchService;
use App\Services\Ranked\RankTierResolver;
use Illuminate\Http\Request;

class RankedMatchController extends Controller
{
    public function __construct(private readonly RankedMatchService $service) {}

    public function show(Request $request, RankedMatch $match, RankTierResolver $tiers)
    {
        $match->load(['season.formats', 'season.club', 'playerOne', 'playerTwo', 'submissions.submitter', 'transactions.profile']);
        $this->authorize('view', $match);
        $pending = $match->submissions->where('status', 'pending')->sortByDesc('id')->first();

        return response()->view('ranked.match', ['match' => $match, 'pending' => $pending, 'tiers' => $tiers])->header('Cache-Control', 'private, no-store');
    }

    public function results(SubmitRankedResultRequest $request, RankedMatch $match)
    {
        $data = $request->validated();
        $s = $this->service->submit($match, $request->user(), (int) $data['score_one'], (int) $data['score_two'], $data['idempotency_key'], $request->ip());

        return $request->expectsJson() ? response()->json(['id' => $s->id, 'status' => $s->status], $s->wasRecentlyCreated ? 201 : 200)
            : back()->with('status', 'Placar enviado. Aguarde a confirmação do adversário.');
    }

    public function confirm(Request $request, RankedMatch $match)
    {
        $m = $this->service->confirm($match, $request->user());

        return $request->expectsJson() ? response()->json(['status' => $m->status]) : back()->with('status', 'Resultado confirmado. O ranking foi atualizado.');
    }

    public function dispute(Request $request, RankedMatch $match)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->service->dispute($match, $request->user(), $data['reason']);

        return back()->with('status', 'Placar contestado. Nenhum ponto muda até a resolução.');
    }

    public function cancel(Request $request, RankedMatch $match)
    {
        $this->service->cancel($match, $request->user());

        return redirect()->route('ranked.index')->with('status', 'Partida cancelada. Nada foi pontuado.');
    }

    public function resolve(ResolveRankedMatchRequest $request, RankedMatch $match)
    {
        $data = $request->validated();
        $this->service->resolve($match, $request->user(), (int) $data['score_one'], (int) $data['score_two'], $data['reason']);

        return back()->with('status', 'Disputa resolvida e ranking atualizado.');
    }

    public function invalidate(Request $request, RankedMatch $match)
    {
        $this->authorize('invalidate', $match);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $changed = $this->service->invalidate($match, $request->user(), $data['reason']);

        return back()->with('status', 'Partida invalidada. '.count($changed).' rating(s) recalculado(s) com lançamento de correção.');
    }
}
