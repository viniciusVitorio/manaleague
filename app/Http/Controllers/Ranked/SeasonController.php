<?php

namespace App\Http\Controllers\Ranked;

use App\Http\Controllers\Controller;
use App\Models\RankedSeason;
use App\Services\Ranked\LeaderboardService;
use App\Services\Ranked\SeasonService;
use Illuminate\Http\Request;

class SeasonController extends Controller
{
    public function show(Request $request, RankedSeason $season, SeasonService $seasons, LeaderboardService $board)
    {
        abort_unless($seasons->visibleSeasons($request->user())->contains('id', $season->id), 404);
        $season = $season->fresh(['formats', 'club']);
        $tops = $season->formats->where('enabled', true)->mapWithKeys(fn ($f) => [$f->format => $season->status === 'finished'
            ? $season->snapshots()->with('profile')->where('format', $f->format)->whereNotNull('rank')->orderBy('rank')->limit(10)->get()
                ->map(fn ($s) => ['rank' => $s->rank, 'name' => $s->profile?->ranked_public ? $s->profile->nickname : 'Jogador oculto', 'rating' => $s->final_rating, 'tier' => $s->tier, 'played' => $s->stats['played'] ?? 0])
            : $board->rows($season, $f->format)->take(10)->map(fn ($r) => ['rank' => $r['rank'], 'name' => $r['name'], 'rating' => $r['rating']->rating, 'tier' => $r['tier']['division'], 'played' => $r['rating']->played])]);
        $mine = $season->snapshots()->where('player_profile_id', $request->user()->playerProfile?->id)->get();

        return view('ranked.season', compact('season', 'tops', 'mine'));
    }
}
