<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;

class PlayerController extends Controller
{
    public function destroy(Tournament $tournament, Player $player): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409, 'O torneio ja comecou.');
        abort_unless($player->tournament_id === $tournament->id, 404);
        $player->delete();

        return back()->with('status', 'Jogador removido.');
    }
}
