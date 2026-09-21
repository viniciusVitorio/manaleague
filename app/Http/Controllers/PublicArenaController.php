<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Tournament;
use App\Services\StandingsService;
use Illuminate\View\View;

class PublicArenaController extends Controller
{
    public function __invoke(string $token, StandingsService $standings): View
    {
        $tournament = Tournament::query()->where('public_slug', $token)->firstOrFail();
        $tournament->load(['players', 'matches' => fn ($query) => $query->orderBy('round')->orderBy('id'), 'matches.playerOne', 'matches.playerTwo']);
        $ranking = $standings->for($tournament);
        $rounds = $tournament->matches->groupBy('round');
        $final = $tournament->matches->firstWhere('stage', GameMatch::STAGE_FINAL);
        $thirdPlace = $tournament->matches->firstWhere('stage', GameMatch::STAGE_THIRD_PLACE);

        return view('arena.public', compact('tournament', 'ranking', 'rounds', 'final', 'thirdPlace'));
    }
}
