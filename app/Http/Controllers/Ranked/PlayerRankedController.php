<?php

namespace App\Http\Controllers\Ranked;

use App\Http\Controllers\Controller;
use App\Models\PlayerProfile;
use App\Models\PlayerRating;
use App\Models\RatingSeasonSnapshot;
use App\Models\RatingTransaction;
use App\Services\Ranked\RankTierResolver;
use App\Services\Ranked\SeasonService;
use Illuminate\Http\Request;

class PlayerRankedController extends Controller
{
    public function show(Request $request, PlayerProfile $profile, SeasonService $seasons, RankTierResolver $tiers)
    {
        $own = $profile->account_user_id === $request->user()->id;
        abort_unless($own || ($profile->account_user_id && $profile->ranked_public), 404);
        $seasonIds = $seasons->visibleSeasons($request->user())->pluck('id');
        $ratings = PlayerRating::query()->with('season')->where('player_profile_id', $profile->id)->whereIn('season_id', $seasonIds)->where('played', '>', 0)->orderByDesc('updated_at')->get();
        $history = RatingTransaction::query()->with(['rankedMatch.playerOne', 'rankedMatch.playerTwo', 'season'])->where('player_profile_id', $profile->id)
            ->whereIn('season_id', $seasonIds)->latest('effective_at')->latest('id')->paginate(25);
        $snapshots = RatingSeasonSnapshot::query()->with('season')->where('player_profile_id', $profile->id)->whereIn('season_id', $seasonIds)->latest('id')->get();

        return view('ranked.player', compact('profile', 'ratings', 'history', 'snapshots', 'tiers', 'own'));
    }
}
