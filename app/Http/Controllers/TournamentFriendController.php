<?php

namespace App\Http\Controllers;

use App\Models\PlayerProfile;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TournamentFriendController extends Controller
{
    public function store(Request $request, Tournament $tournament, PlayerProfile $profile): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409);
        abort_unless($profile->user_id === $request->user()->id && $profile->is_friend, 403);
        $player = $tournament->players()->firstOrCreate(
            ['player_profile_id' => $profile->id],
            ['name' => $profile->nickname, 'deck_name' => $profile->preferred_deck_name, 'deck_colors' => $profile->preferred_deck_colors],
        );
        return back()->with('status', $player->wasRecentlyCreated ? 'Amigo adicionado ao torneio.' : 'Este amigo já está inscrito.');
    }
}
