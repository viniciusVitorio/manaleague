<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\PairingService;
use Illuminate\Http\RedirectResponse;
use LogicException;

class TournamentFlowController extends Controller
{
    public function start(Tournament $tournament, PairingService $pairings): RedirectResponse
    {
        $this->authorize('update', $tournament);

        try {
            $pairings->generateLeague($tournament);
        } catch (LogicException $exception) {
            return back()->withErrors(['tournament' => $exception->getMessage()]);
        }

        return back()->with('status', 'Rodadas geradas. Que comecem os jogos!');
    }

    public function finals(Tournament $tournament, PairingService $pairings): RedirectResponse
    {
        $this->authorize('update', $tournament);

        try {
            $pairings->generateFinals($tournament);
        } catch (LogicException $exception) {
            return back()->withErrors(['tournament' => $exception->getMessage()]);
        }

        return back()->with('status', 'Final e disputa de terceiro lugar definidas!');
    }
}
