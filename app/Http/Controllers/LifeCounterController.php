<?php

namespace App\Http\Controllers;

use App\Models\Challenge;
use App\Models\GameMatch;
use App\Models\RankedMatch;
use App\Models\Tournament;
use App\Services\LifeCounterService;
use App\Services\LifeLogService;
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

    /** Gráfico da partida. Quem não pode ver a partida (ou partida sem histórico) recebe 404. */
    public function showLog(Request $request, LifeLogService $logs, string $type, int $id)
    {
        $chart = $logs->chartFor($type, $id, $request->user());
        abort_unless($chart, 404);
        $view = $request->ajax() ? 'life.partials.chart' : 'life.history';

        return response()->view($view, ['chart' => $chart])->header('Cache-Control', 'private, no-store');
    }

    /** Histórico de vida da partida, enviado pelo contador durante o jogo e ao sair da tela. */
    public function storeLog(Request $request, LifeLogService $logs, string $type, int $id)
    {
        $data = $request->validate([
            'games' => ['present', 'array', 'max:5'],
            'games.*.winner' => ['nullable', 'integer'],
            'games.*.points' => ['present', 'array', 'max:'.LifeLogService::MAX_POINTS],
            'games.*.points.*' => ['array', 'size:4'],
            'games.*.points.*.*' => ['integer'],
            'games.*.out' => ['nullable', 'array', 'max:12'],
            'games.*.out.*' => ['array', 'size:2'],
            'games.*.out.*.*' => ['integer'],
        ]);
        $status = $logs->store($type, $id, $request->user(), $data['games']);

        return response()->json(['status' => $status], $status === 'created' ? 201 : 200);
    }

    private function render(array $config)
    {
        if (! $config['open']) {
            return redirect($config['resultUrl'])->with('status', 'Esta partida não está aberta para o contador de vida.');
        }

        return response()->view('life.counter', ['config' => $config])->header('Cache-Control', 'private, no-store');
    }
}
