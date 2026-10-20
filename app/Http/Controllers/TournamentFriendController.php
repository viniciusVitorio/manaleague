<?php

namespace App\Http\Controllers;

use App\Models\PlayerProfile;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TournamentFriendController extends Controller
{
    public function store(Request $request, Tournament $tournament, PlayerProfile $profile): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($request->user()->friendProfiles()->whereKey($profile->id)->wherePivot('status', 'accepted')->exists(), 403);

        $result = DB::transaction(function () use ($tournament, $profile): string {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if ($locked->status !== Tournament::STATUS_SETUP) return 'closed';
            if ($locked->players()->where('player_profile_id', $profile->id)->exists()) return 'already';
            if ($locked->players()->where('name', $profile->nickname)->exists()) return 'name_taken';
            if ($locked->max_players && $locked->players()->count() >= $locked->max_players) return 'full';

            $locked->players()->create([
                'player_profile_id' => $profile->id, 'name' => $profile->nickname,
                'deck_id' => $profile->decks()->where('is_primary', true)->value('id'),
                'deck_name' => $profile->preferred_deck_name, 'deck_colors' => $profile->preferred_deck_colors,
            ]);

            return 'added';
        });

        return match ($result) {
            'closed' => abort(409, 'As inscrições deste torneio foram encerradas.'),
            'full' => abort(409, 'O torneio atingiu o limite de participantes.'),
            'already' => back()->with('status', 'Este amigo já está inscrito.'),
            'name_taken' => back()->withErrors(['player' => 'Já existe um jogador chamado "'.$profile->nickname.'" neste torneio.']),
            default => back()->with('status', 'Amigo adicionado ao torneio.'),
        };
    }
}
