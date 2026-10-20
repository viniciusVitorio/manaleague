<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TournamentInviteController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $tournament = Tournament::query()->where('invite_token', $token)->firstOrFail();
        $profile = $request->user()->ensurePlayerProfile();
        $profile->load('decks');
        $entry = $tournament->players()->where('player_profile_id', $profile->id)->first();
        return view('tournaments.invite', compact('tournament', 'profile', 'entry'));
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $tournament = Tournament::query()->where('invite_token', $token)->firstOrFail();
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409, 'As inscrições deste torneio foram encerradas.');
        $profile = $request->user()->ensurePlayerProfile();

        $validated = TournamentEntryController::validatedDeck($request, $profile);

        // Checagem de status, limite e duplicidade dentro da mesma transacao com lock:
        // dois convidados entrando ao mesmo tempo nao conseguem estourar o max_players.
        $result = DB::transaction(function () use ($tournament, $profile, $validated): string {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if ($locked->status !== Tournament::STATUS_SETUP) return 'closed';
            if ($locked->players()->where('player_profile_id', $profile->id)->exists()) return 'already';
            if ($locked->players()->where('name', $profile->nickname)->exists()) return 'name_taken';
            if ($locked->max_players && $locked->players()->count() >= $locked->max_players) return 'full';

            $locked->players()->create([
                'player_profile_id' => $profile->id, 'name' => $profile->nickname, 'deck_id' => $validated['deck_id'],
                'deck_name' => $validated['deck_name'] ?: $profile->preferred_deck_name,
                'deck_colors' => $validated['deck_colors'] ?: $profile->preferred_deck_colors,
            ]);

            return 'joined';
        });

        return match ($result) {
            'closed' => abort(409, 'As inscrições deste torneio foram encerradas.'),
            'full' => abort(409, 'O torneio atingiu o limite de participantes.'),
            'already' => back()->with('status', 'Você já está inscrito neste torneio.'),
            'name_taken' => back()->withErrors(['nickname' => 'Já existe um jogador com o apelido "'.$profile->nickname.'" neste torneio. Altere seu apelido no perfil e tente de novo.']),
            default => redirect()->route('arena.public', $tournament->public_slug)->with('status', 'Inscrição confirmada!'),
        };
    }
}
