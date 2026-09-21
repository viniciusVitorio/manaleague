<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlayerController extends Controller
{
    public function store(Request $request, Tournament $tournament): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409, 'O torneio ja comecou.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('players')->where('tournament_id', $tournament->id)],
            'deck_name' => ['nullable', 'string', 'max:100'],
            'deck_colors' => ['nullable', 'string', 'max:10', 'regex:/^[WUBRGC,]*$/'],
        ]);

        $tournament->players()->create($validated);

        return back()->with('status', 'Jogador adicionado.');
    }

    public function destroy(Tournament $tournament, Player $player): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409, 'O torneio ja comecou.');
        abort_unless($player->tournament_id === $tournament->id, 404);
        $player->delete();

        return back()->with('status', 'Jogador removido.');
    }
}
