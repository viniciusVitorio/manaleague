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
        $profile = auth()->user()?->playerProfile()->with('decks')->first();
        $entry = $profile ? $tournament->players->firstWhere('player_profile_id', $profile->id) : null;
        $snapshot = app(\App\Services\LiveDisplayService::class)->snapshot($tournament);
        $slug = $tournament->public_slug;
        return view('arena.public', compact('snapshot', 'slug', 'tournament', 'ranking', 'rounds', 'final', 'thirdPlace', 'recap', 'profile', 'entry'));
    }
}
