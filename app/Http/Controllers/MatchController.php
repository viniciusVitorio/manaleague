<?php
namespace App\Http\Controllers;

use App\Models\{GameMatch, Tournament};
use App\Services\MatchResultService;
use Illuminate\Http\{RedirectResponse, Request};
use LogicException;

class MatchController extends Controller
{
    public function update(Request $request, Tournament $tournament, GameMatch $match): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($match->tournament_id === $tournament->id, 404);
        $correction = $match->status === GameMatch::STATUS_FINISHED;
        try {
            app(MatchResultService::class)->record($tournament, $match, $request->only(['player_one_games', 'player_two_games', 'placements']));
            app(\App\Services\Community\CommunityEvents::class)->tournamentProgress($tournament);
        } catch (LogicException $exception) {
            abort(409, $exception->getMessage());
        }
        return back()->with('status', $correction ? 'Resultado corrigido.' : 'Resultado salvo.');
    }
}
