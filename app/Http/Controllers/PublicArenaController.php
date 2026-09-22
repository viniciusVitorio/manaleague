<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Tournament;
use App\Services\StandingsService;
use App\Services\TournamentRecapService;
use Illuminate\View\View;

class PublicArenaController extends Controller
{
    public function __invoke(string $token, StandingsService $standings, TournamentRecapService $recaps): View
    {
        $tournament = Tournament::query()->where('public_slug', $token)->firstOrFail();
        $tournament->load(['players.profile', 'matches' => fn ($query) => $query->orderBy('round')->orderBy('id'), 'matches.playerOne.profile', 'matches.playerTwo.profile']);
        $ranking = $standings->for($tournament);
        $rounds = $tournament->matches->groupBy('round');
        $final = $tournament->matches->firstWhere('stage', GameMatch::STAGE_FINAL);
        $thirdPlace = $tournament->matches->firstWhere('stage', GameMatch::STAGE_THIRD_PLACE);
        $recap = $recaps->for($tournament);
        return view('arena.public', compact('tournament', 'ranking', 'rounds', 'final', 'thirdPlace', 'recap'));
    }
}
