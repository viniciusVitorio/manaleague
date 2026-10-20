<?php

namespace App\Http\Controllers\Ranked;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Services\Ranked\LeaderboardService;
use App\Services\Ranked\SeasonService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class LeaderboardController extends Controller
{
    public function index(Request $request, SeasonService $seasons, LeaderboardService $board)
    {
        $visible = $seasons->visibleSeasons($request->user());
        $season = $visible->firstWhere('slug', $request->query('season')) ?? $visible->first();
        // Formato mais jogado primeiro, para nao abrir numa tabela vazia.
        $formats = $season ? $season->formats->where('enabled', true)->pluck('format')
            ->sortByDesc(fn ($f) => \App\Models\PlayerRating::where(['season_id' => $season->id, 'format' => $f])->where('played', '>', 0)->count())->values() : collect();
        $format = $formats->contains($request->query('format')) ? $request->query('format') : $formats->first();
        $clubs = Club::whereHas('members', fn ($q) => $q->where('users.id', $request->user()->id))->orderBy('name')->get();
        $clubId = $clubs->firstWhere('id', (int) $request->query('club'))?->id; // so filtra por clubes dos quais faz parte
        $rows = collect();
        if ($season && $format) {
            $rows = $season->status === 'finished'
                ? $season->snapshots()->with('profile')->where('format', $format)->whereNotNull('rank')->orderBy('rank')->get()
                    ->filter(fn ($s) => ! $clubId || in_array($s->profile?->account_user_id, Club::find($clubId)->members()->pluck('users.id')->all(), true))
                    ->values()->map(fn ($s, $i) => ['rank' => $clubId ? $i + 1 : $s->rank, 'snapshot' => $s, 'name' => $s->profile?->ranked_public ? $s->profile->nickname : 'Jogador oculto'])
                : $board->rows($season, $format, $clubId);
        }
        $page = max(1, $request->integer('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, 50)->values(), $rows->count(), 50, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('ranked.leaderboard', compact('visible', 'season', 'formats', 'format', 'clubs', 'clubId', 'paginator'));
    }
}
