<?php

namespace App\Http\Controllers\Ranked;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\PlayerRating;
use App\Models\RankedMatch;
use App\Models\RatingTransaction;
use App\Services\Ranked\MatchmakingService;
use App\Services\Ranked\RankTierResolver;
use App\Services\Ranked\SeasonService;
use Illuminate\Http\Request;

class RankedDashboardController extends Controller
{
    public function index(Request $request, SeasonService $seasons, MatchmakingService $queue, RankTierResolver $tiers)
    {
        $user = $request->user();
        $profile = $user->ensurePlayerProfile();
        $visible = $seasons->visibleSeasons($user);
        $active = $visible->where('status', 'active')->values();
        $ratings = PlayerRating::query()->with('season')->where('player_profile_id', $profile->id)
            ->whereIn('season_id', $visible->whereIn('status', ['active', 'closing'])->pluck('id'))->orderByDesc('rating')->get();
        $recent = RatingTransaction::query()->with(['rankedMatch.playerOne', 'rankedMatch.playerTwo'])->where('player_profile_id', $profile->id)->latest('effective_at')->latest('id')->limit(10)->get();
        $open = RankedMatch::query()->with(['playerOne', 'playerTwo', 'season'])->whereIn('status', ['pending', 'reported', 'disputed'])
            ->where(fn ($q) => $q->where('player_one_profile_id', $profile->id)->orWhere('player_two_profile_id', $profile->id))->latest('id')->get();
        $clubs = Club::whereHas('members', fn ($q) => $q->where('users.id', $user->id))->orderBy('name')->get();
        $activeFormats = $active->flatMap(fn ($s) => $s->formats->where('enabled', true)->pluck('format'))->unique()->values();

        return response()->view('ranked.index', [
            'profile' => $profile, 'seasons' => $visible, 'active' => $active, 'ratings' => $ratings, 'recent' => $recent, 'open' => $open,
            'clubs' => $clubs, 'activeFormats' => $activeFormats, 'queue' => $queue->status($user), 'tiers' => $tiers,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function privacy(Request $request)
    {
        $data = $request->validate(['ranked_public' => ['required', 'boolean']]);
        $request->user()->ensurePlayerProfile()->update(['ranked_public' => (bool) $data['ranked_public']]);

        return back()->with('status', $data['ranked_public'] ? 'Seu apelido aparece no ranking.' : 'Você aparece como "Jogador oculto" no ranking.');
    }

    public function rules()
    {
        return view('ranked.rules');
    }
}
