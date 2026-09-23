<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TournamentInviteController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $tournament = Tournament::query()->where('invite_token', $token)->firstOrFail();
        $profile = $request->user()->ensurePlayerProfile();
        $profile->load('decks');
        return view('tournaments.invite', compact('tournament', 'profile'));
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $tournament = Tournament::query()->where('invite_token', $token)->firstOrFail();
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409, 'As inscrições deste torneio foram encerradas.');
        abort_if($tournament->max_players && $tournament->players()->count() >= $tournament->max_players, 409, 'O torneio atingiu o limite de participantes.');
        $profile = $request->user()->ensurePlayerProfile();

        if ($tournament->players()->where('player_profile_id', $profile->id)->exists()) {
            return back()->with('status', 'Você já está inscrito neste torneio.');
        }

        $validated = $request->validate([
            'deck_name' => ['nullable', 'string', 'max:100'],
            'deck_colors' => ['nullable', 'string', 'max:10', 'regex:/^[WUBRGC,]*$/'],
        ]);

        $tournament->players()->create([
            'player_profile_id' => $profile->id, 'name' => $profile->nickname,
            'deck_name' => $validated['deck_name'] ?: $profile->preferred_deck_name,
            'deck_colors' => $validated['deck_colors'] ?: $profile->preferred_deck_colors,
        ]);

        return redirect()->route('arena.public', $tournament->public_slug)->with('status', 'Inscrição confirmada!');
    }
}
