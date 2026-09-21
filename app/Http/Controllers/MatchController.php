<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function update(Request $request, Tournament $tournament, GameMatch $match): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($match->tournament_id === $tournament->id, 404);
        abort_if($match->status === GameMatch::STATUS_BYE, 409, 'BYE nao pode ser alterado.');

        $validated = $request->validate([
            'player_one_games' => ['required', 'integer', 'min:0', 'max:3'],
            'player_two_games' => ['required', 'integer', 'min:0', 'max:3'],
        ]);

        if (($validated['player_one_games'] + $validated['player_two_games']) === 0) {
            return back()->withErrors(['score' => 'O placar nao pode ser 0 a 0.']);
        }

        if ($match->stage !== GameMatch::STAGE_LEAGUE && $validated['player_one_games'] === $validated['player_two_games']) {
            return back()->withErrors(['score' => 'Partidas decisivas nao podem terminar empatadas.']);
        }

        $match->update([
            ...$validated,
            'status' => GameMatch::STATUS_FINISHED,
            'played_at' => now(),
        ]);

        if ($tournament->status === Tournament::STATUS_FINALS
            && ! $tournament->matches()->whereIn('stage', [GameMatch::STAGE_FINAL, GameMatch::STAGE_THIRD_PLACE])->where('status', GameMatch::STATUS_PENDING)->exists()) {
            $tournament->update(['status' => Tournament::STATUS_FINISHED]);
        }

        return back()->with('status', 'Resultado salvo.');
    }
}
