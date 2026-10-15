<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Tournament;
use App\Services\AchievementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MatchController extends Controller
{
    public function update(Request $request, Tournament $tournament, GameMatch $match): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($match->tournament_id === $tournament->id, 404);
        abort_if($match->status === GameMatch::STATUS_BYE, 409, 'BYE nao pode ser alterado.');

        // Resultados da liga ficam travados depois que as finais sao geradas: alterar a
        // classificacao nesse ponto deixaria os finalistas inconsistentes com a tabela.
        $isLeague = $match->stage === GameMatch::STAGE_LEAGUE;
        $editable = $isLeague
            ? $tournament->status === Tournament::STATUS_LEAGUE
            : in_array($tournament->status, [Tournament::STATUS_FINALS, Tournament::STATUS_FINISHED], true);
        abort_unless($editable, 409, $isLeague
            ? 'A fase de liga ja foi encerrada; os resultados nao podem mais ser alterados.'
            : 'Esta partida nao pode ser alterada neste momento.');

        $validated = array_map('intval', $request->validate([
            'player_one_games' => ['required', 'integer', 'min:0', 'max:3'],
            'player_two_games' => ['required', 'integer', 'min:0', 'max:3'],
        ]));

        if (($validated['player_one_games'] + $validated['player_two_games']) === 0) {
            return back()->withErrors(['score' => 'O placar nao pode ser 0 a 0.']);
        }

        if (! $isLeague && $validated['player_one_games'] === $validated['player_two_games']) {
            return back()->withErrors(['score' => 'Partidas decisivas nao podem terminar empatadas.']);
        }

        $previous = $match->only(['player_one_games', 'player_two_games', 'status']);

        DB::transaction(function () use ($tournament, $match, $validated): void {
            $match->update([
                ...$validated,
                'status' => GameMatch::STATUS_FINISHED,
                'played_at' => $match->played_at ?? now(),
            ]);

            if ($tournament->status === Tournament::STATUS_FINALS
                && ! $tournament->matches()->whereIn('stage', [GameMatch::STAGE_FINAL, GameMatch::STAGE_THIRD_PLACE])->where('status', GameMatch::STATUS_PENDING)->exists()) {
                $tournament->update(['status' => Tournament::STATUS_FINISHED]);
            }
        });

        // Trilha de auditoria (STRIDE: Repudiation): quem alterou qual placar e o valor anterior.
        Log::info('match.result_saved', [
            'user_id' => $request->user()->id,
            'tournament_id' => $tournament->id,
            'match_id' => $match->id,
            'before' => $previous,
            'after' => $validated,
        ]);

        // Conquistas dos dois jogadores ficam prontas para a proxima visita ao perfil.
        $achievements = app(AchievementService::class);
        foreach ([$match->playerOne?->profile, $match->playerTwo?->profile] as $profile) {
            if ($profile) $achievements->sync($profile);
        }

        return back()->with('status', $previous['status'] === GameMatch::STATUS_FINISHED ? 'Resultado corrigido.' : 'Resultado salvo.');
    }
}
