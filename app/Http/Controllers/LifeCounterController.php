<?php

namespace App\Http\Controllers;

use App\Models\Challenge;
use App\Models\GameMatch;
use App\Models\RankedMatch;
use App\Models\Tournament;
use App\Services\LifeCounterService;
use Illuminate\Http\Request;

/** Contador de vida em tela cheia vinculado a uma partida (torneio, ranqueada ou desafio). */
class LifeCounterController extends Controller
{
    public function __construct(private readonly LifeCounterService $service) {}

    public function tournament(Request $request, Tournament $tournament, GameMatch $match)
    {
        return $this->render($this->service->forTournament($tournament, $match, $request->user()));
    }

    public function ranked(Request $request, RankedMatch $match)
    {
        return $this->render($this->service->forRanked($match, $request->user()));
    }

    public function challenge(Request $request, Challenge $challenge)
    {
        if ($challenge->is_ranked && $challenge->involves($request->user())) {
            $ranked = RankedMatch::where(['source_type' => 'challenge', 'source_id' => $challenge->id])->first();
            if ($ranked) {
                return redirect()->route('life.ranked', $ranked);
            }
        }

        return $this->render($this->service->forChallenge($challenge, $request->user()));
    }

    private function render(array $config)
    {
        if (! $config['open']) {
            return redirect($config['resultUrl'])->with('status', 'Esta partida não está aberta para o contador de vida.');
        }

        return response()->view('life.counter', ['config' => $config])->header('Cache-Control', 'private, no-store');
    }
}
